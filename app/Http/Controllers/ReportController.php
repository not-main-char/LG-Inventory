<?php

namespace App\Http\Controllers;

use App\Support\FishStock;
use App\Support\SensorReadings;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Contract\Firestore;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ReportController extends Controller
{
    /** Report types the user can export. */
    public const TYPES = [
        'inventory' => 'Inventory',
        'sales'     => 'Sales',
        'harvest'   => 'Harvest records',
        'sensors'   => 'Sensor history',
    ];

    public const REPORT_NAMES = [
        'inventory' => 'Inventory Report',
        'sales'     => 'Sales Report',
        'harvest'   => 'Harvest Report',
        'sensors'   => 'Sensor History Report',
    ];

    protected $firestore;
    protected $rtdb;

    public function __construct(Firestore $firestore, Database $rtdb)
    {
        $this->firestore = $firestore->database();
        $this->rtdb = $rtdb;
    }

    public function index(Request $request)
    {
        $month = $this->validatedMonth($request->query('month'));
        $type = array_key_exists((string) $request->query('type'), self::TYPES) ? $request->query('type') : null;
        $format = in_array($request->query('format'), ['xlsx', 'pdf'], true) ? $request->query('format') : null;
        $qrCode = null;
        $downloadUrl = null;
        $reportLabel = null;
        $expiresAt = null;
        $hasData = null;
        $validationMessage = null;

        if ($type && $format) {
            $rows = $this->rowsFor($type, $month);
            $hasData = count($rows) > 0;
            if (!$hasData) {
                $validationMessage = 'There is no ' . strtolower(self::TYPES[$type]) . ' data for ' . Carbon::createFromFormat('Y-m', $month)->format('F Y') . '. Choose a month that contains data.';
            } else {
                $downloadRoute = 'reports.' . $type;
                $downloadUrl = route($downloadRoute, ['format' => $format, 'month' => $month]);
                $expiresAt = now()->addDays(7);
                $shareUrl = URL::temporarySignedRoute('reports.share', $expiresAt, [
                    'type' => $type,
                    'format' => $format,
                    'month' => $month,
                ]);
                $reportLabel = self::REPORT_NAMES[$type] . ' — ' . Carbon::createFromFormat('Y-m', $month)->format('F Y') . ' — ' . strtoupper($format === 'xlsx' ? 'Excel' : 'PDF');
                $qrCode = base64_encode($this->labeledQrPng($shareUrl, $reportLabel, $expiresAt));
            }
        }

        return view('reports.index', compact('month', 'type', 'format', 'qrCode', 'downloadUrl', 'reportLabel', 'expiresAt', 'hasData', 'validationMessage'));
    }

    public function share(Request $request, string $type, string $format)
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

        $month = $this->validatedMonth($request->query('month'));
        $expiresAt = $request->query('expires') ? Carbon::createFromTimestamp((int) $request->query('expires'), 'Asia/Manila') : now()->addDays(7);
        $downloadUrl = URL::temporarySignedRoute('reports.shared-download', $expiresAt, [
            'type' => $type,
            'format' => $format,
            'month' => $month,
        ]);

        return view('reports.share', [
            'type' => $type,
            'format' => $format,
            'month' => $month,
            'downloadUrl' => $downloadUrl,
            'expiresAt' => $expiresAt,
            'reportLabel' => self::REPORT_NAMES[$type] . ' — ' . Carbon::createFromFormat('Y-m', $month)->format('F Y') . ' — ' . strtoupper($format === 'xlsx' ? 'Excel' : 'PDF'),
        ]);
    }

    public function sharedDownload(Request $request, string $type, string $format)
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

        return $this->monthReport($type, $request, $format);
    }

    /** One month of one report type, in the chosen file format. */
    protected function monthReport(string $type, Request $request, string $format)
    {
        $month = $this->validatedMonth($request->query('month'));
        $title = self::REPORT_NAMES[$type] . ' - ' . Carbon::createFromFormat('Y-m', $month)->format('F Y');

        return $this->download($this->rowsFor($type, $month), $title, $type . '-report-' . $month, $format);
    }

    protected function rowsFor(string $type, string $month): array
    {
        return match ($type) {
            'harvest' => $this->harvestRows($month),
            'sensors' => $this->sensorRows($month),
            'sales'   => $this->salesRows($month),
            default   => $this->inventoryRows($month),
        };
    }

    public function downloadHarvest(Request $request, string $format)
    {
        return $this->monthReport('harvest', $request, $format);
    }

    public function downloadSensors(Request $request, string $format)
    {
        return $this->monthReport('sensors', $request, $format);
    }

    /** Harvest records of one month: what was stocked or harvested, and how much is left. */
    protected function harvestRows(string $month): array
    {
        $rows = [];
        foreach ($this->firestore->collection('harvests')->documents() as $document) {
            $data = $document->data();
            if (($data['status'] ?? 'active') === 'archived') continue;

            $date = $this->dateValue($data['harvestDate'] ?? null)
                ?? $this->dateValue($data['createdAt'] ?? null);
            if (!$date || $date->format('Y-m') !== $month) continue;

            $rows[] = [
                $data['product'] ?? '',
                $data['kind'] ?? '',
                $data['growthStage'] ?? '',
                $data['batch'] ?? '',
                (float) ($data['quantity'] ?? 0),
                (float) ($data['remainingQuantity'] ?? 0),
                $data['unit'] ?? '',
                $date->format('Y-m-d'),
                $data['source'] ?? '',
                $data['notes'] ?? '',
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $b[7], (string) $a[7]));
        return $rows;
    }

    /** One row per day that has readings, averaged per sensor. */
    protected function sensorRows(string $month): array
    {
        $rows = [];

        foreach (app(FishStock::class)->devices() as $device => $label) {
            $all = $this->rtdb->getReference("devices/{$device}/sensor_history")->getValue();
            if (!is_array($all)) continue;

            // day => [readings, per-sensor sum, per-sensor count]
            $days = [];
            foreach ($all as $reading) {
                if (!is_array($reading)) continue;
                $stamp = SensorReadings::at($reading);
                if (!$stamp || $stamp->format('Y-m') !== $month) continue;

                $day = $stamp->format('Y-m-d');
                $days[$day] ??= ['count' => 0, 'sum' => [], 'seen' => []];
                $days[$day]['count']++;

                foreach (SensorReadings::COLUMNS as $key) {
                    if (!is_numeric($reading[$key] ?? null)) continue;
                    $days[$day]['sum'][$key] = ($days[$day]['sum'][$key] ?? 0) + (float) $reading[$key];
                    $days[$day]['seen'][$key] = ($days[$day]['seen'][$key] ?? 0) + 1;
                }
            }

            ksort($days);
            foreach ($days as $day => $info) {
                $row = [$label, $day, $info['count']];
                foreach (SensorReadings::COLUMNS as $key) {
                    $row[] = isset($info['seen'][$key])
                        ? round($info['sum'][$key] / $info['seen'][$key], 2)
                        : null;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function downloadInventory(Request $request, string $format)
    {
        return $this->monthReport('inventory', $request, $format);
    }

    public function downloadSales(Request $request, string $format)
    {
        return $this->monthReport('sales', $request, $format);
    }

    protected function validatedMonth(?string $month): string
    {
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return Carbon::now('Asia/Manila')->format('Y-m');
        }
        [$year, $monthNumber] = explode('-', $month);
        return checkdate((int) $monthNumber, 1, (int) $year)
            ? $month
            : Carbon::now('Asia/Manila')->format('Y-m');
    }

    protected function inventoryRows(string $month): array
    {
        $rows = [];
        $monthStart = Carbon::createFromFormat('Y-m', $month, 'Asia/Manila')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $documents = $this->firestore->collection('inventory_items')->orderBy('name')->documents();

        foreach ($documents as $document) {
            $data = $document->data();
            if (($data['status'] ?? '') === 'archived') continue;

            $createdAt = $this->dateValue($data['createdAt'] ?? null);
            if ($createdAt && $createdAt->greaterThan($monthEnd)) continue;

            $restocked = 0.0;
            $consumed = 0.0;
            $hadHistoryInMonth = false;
            foreach (($data['stockHistory'] ?? []) as $entry) {
                $entryDate = $this->dateValue($entry['date'] ?? null);
                if (!$entryDate || $entryDate->format('Y-m') !== $month) continue;
                $hadHistoryInMonth = true;
                $quantityText = (string) ($entry['quantity'] ?? '0');
                $quantity = (float) preg_replace('/[^0-9.\-]/', '', $quantityText);
                if (str_starts_with(trim($quantityText), '+') || ($entry['action'] ?? '') === 'Restock') {
                    $restocked += abs($quantity);
                } elseif ($quantity < 0 || str_contains(strtolower((string) ($entry['action'] ?? '')), 'consumption') || str_contains(strtolower((string) ($entry['action'] ?? '')), 'deduction')) {
                    $consumed += abs($quantity);
                }
            }

            // An item that already existed during the selected month is valid report data,
            // even when it had no stock movement in that month.
            if (!$createdAt && !$hadHistoryInMonth) continue;
            $updatedAt = $this->dateValue($data['lastStockUpdate'] ?? $data['createdAt'] ?? null);
            $rows[] = [
                $data['name'] ?? '', $data['type'] ?? '', $data['unit'] ?? '',
                (float) ($data['currentStock'] ?? 0), $data['usageFrequency'] ?? 'manual',
                $restocked, $consumed,
                $data['procurementSource'] ?? 'Not specified',
                $updatedAt?->format('Y-m-d H:i:s') ?? '',
            ];
        }
        return $rows;
    }

    protected function salesRows(string $month): array
    {
        $rows = [];
        $documents = $this->firestore->collection('sales')->orderBy('date', 'desc')->documents();
        foreach ($documents as $document) {
            $data = $document->data();
            if (($data['status'] ?? '') === 'archived') continue;
            $date = $this->dateValue($data['date'] ?? null);
            if (!$date || $date->format('Y-m') !== $month) continue;
            $rows[] = [
                $data['itemName'] ?? '', $data['type'] ?? '', (float) ($data['quantitySold'] ?? 0),
                $this->salesUnitLabel($data['unit'] ?? $data['saleUnit'] ?? ''),
                $this->saleWeightKg($data),
                (float) ($data['saleAmount'] ?? 0), $date->format('Y-m-d'), $data['notes'] ?? '',
            ];
        }
        return $rows;
    }

    /**
     * Kilos a sale represents, so kilos and pieces are never added as one number.
     * A piece sale stores the weight it took off the batch in `weightSold`.
     */
    protected function saleWeightKg(array $data): ?float
    {
        if (isset($data['weightSold']) && is_numeric($data['weightSold'])) {
            return round((float) $data['weightSold'], 4);
        }

        $unit = strtolower(trim((string) ($data['unit'] ?? $data['saleUnit'] ?? '')));
        if (in_array($unit, ['kg', 'kilo', 'kilos', 'kilogram', 'kilograms'], true)) {
            return round((float) ($data['quantitySold'] ?? 0), 4);
        }

        return null; // a piece sale recorded before weights were stored
    }

    protected function labeledQrPng(string $shareUrl, string $reportLabel, Carbon $expiresAt): string
    {
        $qrBinary = QrCode::format('png')->size(520)->margin(1)->generate($shareUrl);
        $qr = new \Imagick();
        $qr->readImageBlob($qrBinary);
        $qr->setImageFormat('png');

        $canvas = new \Imagick();
        $canvas->newImage(600, 740, new \ImagickPixel('white'));
        $canvas->setImageFormat('png');
        $canvas->setImageBackgroundColor(new \ImagickPixel('white'));
        $canvas->compositeImage($qr, \Imagick::COMPOSITE_DEFAULT, 40, 25);

        $font = $this->reportFontPath();
        $titleDraw = new \ImagickDraw();
        $titleDraw->setFillColor(new \ImagickPixel('#14532d'));
        $titleDraw->setFontSize(22);
        $titleDraw->setTextAlignment(\Imagick::ALIGN_CENTER);
        if ($font) $titleDraw->setFont($font);
        $canvas->annotateImage($titleDraw, 300, 590, 0, $reportLabel);

        $instructionDraw = new \ImagickDraw();
        $instructionDraw->setFillColor(new \ImagickPixel('#475569'));
        $instructionDraw->setFontSize(17);
        $instructionDraw->setTextAlignment(\Imagick::ALIGN_CENTER);
        if ($font) $instructionDraw->setFont($font);
        $canvas->annotateImage($instructionDraw, 300, 625, 0, 'Scan to download this report');

        $expiryDraw = new \ImagickDraw();
        $expiryDraw->setFillColor(new \ImagickPixel('#64748b'));
        $expiryDraw->setFontSize(14);
        $expiryDraw->setTextAlignment(\Imagick::ALIGN_CENTER);
        if ($font) $expiryDraw->setFont($font);
        $canvas->annotateImage($expiryDraw, 300, 655, 0, 'Valid until ' . $expiresAt->timezone('Asia/Manila')->format('F d, Y \\a\\t h:i A'));

        $brandDraw = new \ImagickDraw();
        $brandDraw->setFillColor(new \ImagickPixel('#14532d'));
        $brandDraw->setFontSize(14);
        $brandDraw->setTextAlignment(\Imagick::ALIGN_CENTER);
        if ($font) $brandDraw->setFont($font);
        $canvas->annotateImage($brandDraw, 300, 690, 0, 'LG Agri-Tourism');

        $png = $canvas->getImagesBlob();
        $qr->clear();
        $qr->destroy();
        $canvas->clear();
        $canvas->destroy();
        return $png;
    }

    protected function reportFontPath(): ?string
    {
        $fontCandidates = [
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\segoeui.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
        ];
        foreach ($fontCandidates as $font) {
            if (is_file($font)) return $font;
        }
        return null;
    }

    protected function salesUnitLabel(string $unit): string
    {
        return match (strtolower(trim($unit))) {
            'pcs', 'piece', 'pieces' => 'Pcs',
            'kg', 'kilo', 'kilos', 'kilogram', 'kilograms' => 'Kilos',
            default => $unit !== '' ? ucfirst($unit) : 'Not specified',
        };
    }

    protected function dateValue($value): ?Carbon
    {
        try {
            if ($value instanceof \Google\Cloud\Core\Timestamp) $value = $value->get();
            elseif (is_object($value) && method_exists($value, 'get')) $value = $value->get();
            elseif (is_object($value) && method_exists($value, 'toDateTime')) $value = $value->toDateTime();
            return $value ? Carbon::parse($value)->setTimezone('Asia/Manila') : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function download(array $rows, string $title, string $filename, string $format)
    {
        $format = strtolower($format);
        $lower = strtolower($title);
        $headers = match (true) {
            str_contains($lower, 'inventory') => ['Item Name', 'Type', 'Stock Unit', 'Quantity in Stock', 'Stock Type', 'Monthly Restocked', 'Monthly Consumption', 'Procurement Source', 'Last Stock Update'],
            str_contains($lower, 'harvest')   => ['Item Name', 'Type', 'Growth Stage', 'Batch', 'Quantity', 'Remaining', 'Unit', 'Harvest Date', 'Source', 'Notes'],
            str_contains($lower, 'sensor')    => ['Pond', 'Date', 'Readings', 'Air Temp (°C)', 'Water Temp (°C)', 'Turbidity (%)', 'Humidity (%)', 'pH', 'Water Level (%)'],
            default => ['Item Name', 'Type', 'Quantity Sold', 'Unit Sold', 'Weight (kg)', 'Income (PHP)', 'Date of Sale', 'Notes'],
        };
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

        // Totals are kept per unit: a kilo total and a piece total are different
        // things and must never be summed into one figure.
        if (str_contains(strtolower($title), 'sales') && $rows) {
            $kilos = 0.0;
            $pieces = 0;
            $income = 0.0;
            foreach ($rows as $row) {
                $income += (float) ($row[5] ?? 0);
                if (($row[3] ?? '') === 'Kilos') {
                    $kilos += (float) ($row[4] ?? 0);
                } elseif (($row[3] ?? '') === 'Pcs') {
                    $pieces += (int) round((float) ($row[2] ?? 0));
                }
            }
            $rows[] = ['', '', '', '', '', '', '', ''];
            $rows[] = ['Total kilos sold', '', '', 'kg', round($kilos, 3), '', '', ''];
            $rows[] = ['Total pieces sold', '', $pieces, 'pcs', '', '', '', ''];
            $rows[] = ['Total income', '', '', '', '', round($income, 2), '', ''];
        }

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(substr($title, 0, 31));
            $lastColumn = chr(64 + count($headers));
            $sheet->mergeCells('A1:' . $lastColumn . '1');
            $sheet->setCellValue('A1', 'LG Agri-Tourism');
            $sheet->mergeCells('A2:' . $lastColumn . '2');
            $sheet->setCellValue('A2', $title);
            $sheet->mergeCells('A3:' . $lastColumn . '3');
            $sheet->setCellValue('A3', 'Official system-generated report');
            $sheet->fromArray([$headers], null, 'A4');
            if ($rows) $sheet->fromArray($rows, null, 'A5');
            foreach (range('A', $lastColumn) as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A3')->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('666666'));
            $sheet->getStyle('A4:' . $lastColumn . '4')->getFont()->setBold(true);
            $sheet->freezePane('A5');
            $writer = new Xlsx($spreadsheet);
            $temp = tempnam(sys_get_temp_dir(), 'lg-report-');
            $writer->save($temp);
            return response()->download($temp, $filename . '.xlsx')->deleteFileAfterSend(true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $logoDataUri = '';
        $logoPath = public_path('images/logo.png');
        if (is_file($logoPath)) {
            $logoDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
        $html = view('reports.pdf', compact('title', 'headers', 'rows', 'logoDataUri'))->render();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.pdf"',
        ]);
    }
}
