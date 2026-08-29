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

        if ($type && $format) {
            $downloadRoute = $type === 'inventory' ? 'reports.inventory' : 'reports.sales';
            $downloadUrl = route($downloadRoute, ['format' => $format, 'month' => $month]);
            $shareUrl = URL::temporarySignedRoute('reports.share', now()->addDays(7), [
                'type' => $type,
                'format' => $format,
                'month' => $month,
            ]);
            $qrCode = QrCode::size(260)->margin(1)->generate($shareUrl);
            $reportLabel = ucfirst($type) . ' Report — ' . Carbon::createFromFormat('Y-m', $month)->format('F Y') . ' — ' . strtoupper($format === 'xlsx' ? 'Excel' : 'PDF');
        }

        return view('reports.index', compact('month', 'type', 'format', 'qrCode', 'downloadUrl', 'reportLabel'));
    }

    public function share(Request $request, string $type, string $format)
    {
        abort_unless(in_array($type, ['inventory', 'sales'], true), 404);
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

        $month = $this->validatedMonth($request->query('month'));
        $downloadUrl = URL::temporarySignedRoute('reports.shared-download', now()->addDays(7), [
            'type' => $type,
            'format' => $format,
            'month' => $month,
        ]);

        return view('reports.share', [
            'type' => $type,
            'format' => $format,
            'month' => $month,
            'downloadUrl' => $downloadUrl,
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
        $documents = $this->firestore->collection('inventory_items')->orderBy('name')->documents();
        foreach ($documents as $document) {
            $data = $document->data();
            if (($data['status'] ?? '') === 'archived') continue;
            $createdAt = $this->dateValue($data['createdAt'] ?? null);
            $updatedAt = $this->dateValue($data['lastStockUpdate'] ?? $data['createdAt'] ?? null);
            if ($createdAt?->format('Y-m') !== $month && $updatedAt?->format('Y-m') !== $month) continue;
            $rows[] = [
                $data['name'] ?? '', $data['type'] ?? '', $data['unit'] ?? '',
                (float) ($data['currentStock'] ?? 0), $data['usageFrequency'] ?? 'manual',
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
                $data['unit'] ?? '', (float) ($data['saleAmount'] ?? 0), $date->format('Y-m-d'), $data['notes'] ?? '',
            ];
        }
        return $rows;
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
            ? ['Item Name', 'Type', 'Stock Unit', 'Quantity in Stock', 'Stock Type', 'Procurement Source', 'Farm Cost', 'Last Stock Update']
            : ['Item Name', 'Type', 'Quantity Sold', 'Unit', 'Income', 'Date of Sale', 'Notes'];
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(substr($title, 0, 31));
            $sheet->fromArray([$headers], null, 'A1');
            if ($rows) $sheet->fromArray($rows, null, 'A2');
            foreach (range('A', chr(64 + count($headers))) as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
            $sheet->getStyle('A1:' . chr(64 + count($headers)) . '1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            $writer = new Xlsx($spreadsheet);
            $temp = tempnam(sys_get_temp_dir(), 'lg-report-');
            $writer->save($temp);
            return response()->download($temp, $filename . '.xlsx')->deleteFileAfterSend(true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $html = view('reports.pdf', compact('title', 'headers', 'rows'))->render();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.pdf"',
        ]);
    }
}
