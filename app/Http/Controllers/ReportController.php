<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Kreait\Firebase\Contract\Firestore;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ReportController extends Controller
{
    protected $firestore;

    public function __construct(Firestore $firestore)
    {
        $this->firestore = $firestore->database();
    }

    public function index(Request $request)
    {
        $month = $this->validatedMonth($request->query('month'));
        $type = in_array($request->query('type'), ['inventory', 'sales'], true) ? $request->query('type') : null;
        $format = in_array($request->query('format'), ['xlsx', 'pdf'], true) ? $request->query('format') : null;
        $qrCode = null;
        $downloadUrl = null;
        $reportLabel = null;
        $expiresAt = null;
        $hasData = null;
        $validationMessage = null;

        if ($type && $format) {
            $rows = $type === 'inventory' ? $this->inventoryRows($month) : $this->salesRows($month);
            $hasData = count($rows) > 0;
            if (!$hasData) {
                $validationMessage = 'There is no ' . ($type === 'inventory' ? 'inventory' : 'sales') . ' data for ' . Carbon::createFromFormat('Y-m', $month)->format('F Y') . '. Choose a month that contains data.';
            } else {
                $downloadRoute = $type === 'inventory' ? 'reports.inventory' : 'reports.sales';
                $downloadUrl = route($downloadRoute, ['format' => $format, 'month' => $month]);
                $expiresAt = now()->addDays(7);
                $shareUrl = URL::temporarySignedRoute('reports.share', $expiresAt, [
                    'type' => $type,
                    'format' => $format,
                    'month' => $month,
                ]);
                $reportLabel = ucfirst($type) . ' Report — ' . Carbon::createFromFormat('Y-m', $month)->format('F Y') . ' — ' . strtoupper($format === 'xlsx' ? 'Excel' : 'PDF');
                $qrCode = base64_encode($this->labeledQrPng($shareUrl, $reportLabel, $expiresAt));
            }
        }

        return view('reports.index', compact('month', 'type', 'format', 'qrCode', 'downloadUrl', 'reportLabel', 'expiresAt', 'hasData', 'validationMessage'));
    }

    public function share(Request $request, string $type, string $format)
    {
        abort_unless(in_array($type, ['inventory', 'sales'], true), 404);
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
            'reportLabel' => ucfirst($type) . ' Report — ' . Carbon::createFromFormat('Y-m', $month)->format('F Y') . ' — ' . strtoupper($format === 'xlsx' ? 'Excel' : 'PDF'),
        ]);
    }

    public function sharedDownload(Request $request, string $type, string $format)
    {
        abort_unless(in_array($type, ['inventory', 'sales'], true), 404);
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

        return $type === 'inventory'
            ? $this->downloadInventory($request, $format)
            : $this->downloadSales($request, $format);
    }

    public function downloadInventory(Request $request, string $format)
    {
        $month = $this->validatedMonth($request->query('month'));
        $rows = $this->inventoryRows($month);
        $title = 'Inventory Report - ' . Carbon::createFromFormat('Y-m', $month)->format('F Y');
        return $this->download($rows, $title, 'inventory-report-' . $month, $format);
    }

    public function downloadSales(Request $request, string $format)
    {
        $month = $this->validatedMonth($request->query('month'));
        $rows = $this->salesRows($month);
        $title = 'Sales Report - ' . Carbon::createFromFormat('Y-m', $month)->format('F Y');
        return $this->download($rows, $title, 'sales-report-' . $month, $format);
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
                ($data['procurementSource'] ?? '') === 'Farm Purchase' ? (float) ($data['procurementCost'] ?? 0) : '',
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
                $this->salesUnitLabel($data['unit'] ?? $data['saleUnit'] ?? ''), (float) ($data['saleAmount'] ?? 0), $date->format('Y-m-d'), $data['notes'] ?? '',
            ];
        }
        return $rows;
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
        $headers = str_contains(strtolower($title), 'inventory')
            ? ['Item Name', 'Type', 'Stock Unit', 'Quantity in Stock', 'Stock Type', 'Monthly Restocked', 'Monthly Consumption', 'Procurement Source', 'Farm Cost', 'Last Stock Update']
            : ['Item Name', 'Type', 'Quantity Sold', 'Unit Sold', 'Income (PHP)', 'Date of Sale', 'Notes'];
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

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
