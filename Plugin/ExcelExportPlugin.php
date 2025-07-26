<?php

namespace MagoArab\OrderEnhancer\Plugin;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Response\Http\FileFactory;

class ExcelExportPlugin
{
    protected $filesystem;
    protected $logger;
    protected $fileFactory;

    public function __construct(
        Filesystem $filesystem,
        LoggerInterface $logger,
        FileFactory $fileFactory
    ) {
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->fileFactory = $fileFactory;
    }

    /**
     * After get CSV file - ConvertToCsv
     */
    public function afterGetCsvFile($subject, $result)
    {
        $this->logger->info('ExcelExportPlugin: afterGetCsvFile called');
        return $this->processExportResult($result);
    }
    
    /**
     * After get XML file - ConvertToXml
     */
    public function afterGetXmlFile($subject, $result)
    {
        $this->logger->info('ExcelExportPlugin: afterGetXmlFile called');
        return $this->processExportResult($result);
    }
    
    /**
     * Process export result
     */
    protected function processExportResult($result)
    {
        try {
            $this->logger->info('Processing export result: ' . print_r($result, true));
            
            $filePath = null;
            
            // التعامل مع أنواع النتائج المختلفة
            if (is_array($result)) {
                if (isset($result['value'])) {
                    $filePath = $result['value'];
                } elseif (isset($result['file'])) {
                    $filePath = $result['file'];
                }
            } elseif (is_string($result)) {
                $filePath = $result;
            }
            
            if ($filePath) {
                $this->logger->info('Found file path: ' . $filePath);
                $this->enhanceOrderExport($filePath);
            } else {
                $this->logger->info('No valid file path found in result');
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error in ExcelExportPlugin: ' . $e->getMessage());
            $this->logger->error('Stack trace: ' . $e->getTraceAsString());
        }

        return $result;
    }

    /**
     * Enhance order export with proper UTF-8 encoding
     */
    protected function enhanceOrderExport($filePath)
    {
        try {
            $this->logger->info('Starting enhanceOrderExport for file: ' . $filePath);
            
            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            
            // التحقق من المسار الكامل
            $fullPath = $filePath;
            if (!$directory->isExist($fullPath)) {
                // جرب إضافة مسار export إذا لم يكن موجوداً
                $fullPath = 'export/' . basename($filePath);
                if (!$directory->isExist($fullPath)) {
                    $this->logger->info('File does not exist: ' . $filePath . ' or ' . $fullPath);
                    return;
                }
            }
            
            $this->logger->info('Using file path: ' . $fullPath);

            // قراءة محتوى CSV الموجود
            $content = $directory->readFile($fullPath);
            $this->logger->info('File content length: ' . strlen($content));
            
            // التحقق من وجود محتوى
            if (empty($content)) {
                $this->logger->info('Empty file content');
                return;
            }
            
            // إزالة BOM إذا كان موجوداً
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
            
            $lines = explode("\n", $content);
            $this->logger->info('Total lines: ' . count($lines));
            
            if (empty($lines) || empty(trim($lines[0]))) {
                $this->logger->info('Empty CSV file or no header');
                return;
            }

            // معالجة الهيدر
            $header = $this->parseCsvLine($lines[0]);
            $this->logger->info('Original header: ' . implode(', ', $header));
            
            // إزالة الأعمدة غير المرغوب فيها
            $columnsToRemove = [
                'Grand Total (Base)',
                'Grand Total (Purchased)', 
                'Total Refunded',
                'Allocated sources',
                'Pickup Location Code',
                'Created by (Login as Customer)',
                'Tracking Information',
                'Lock',
                'Meta Order ID'
            ];
            
            // التحقق من وجود الأعمدة الخاصة بنا
            $hasGovernorate = in_array('المحافظة', $header);
            $hasProductsOrdered = in_array('اجمالي المنتجات المطلوبة', $header);
            
            $this->logger->info('Has governorate column: ' . ($hasGovernorate ? 'YES' : 'NO'));
            $this->logger->info('Has products ordered column: ' . ($hasProductsOrdered ? 'YES' : 'NO'));
            
            // شيل الأعمدة المش مرغوب فيها وإزالة Customer Phone المكرر
            $this->removeUnwantedColumns($lines, $directory, $fullPath, $columnsToRemove);
            
        } catch (\Exception $e) {
            $this->logger->error('Error enhancing order export: ' . $e->getMessage());
            $this->logger->error('Stack trace: ' . $e->getTraceAsString());
        }
    }
    
    /**
     * Remove unwanted columns from export
     */
    protected function removeUnwantedColumns($lines, $directory, $fullPath, $columnsToRemove)
    {
        try {
            $header = $this->parseCsvLine($lines[0]);
            $this->logger->info('Original header before removal: ' . implode(', ', $header));
            
            // Find columns to remove
            $columnsToRemoveIndexes = [];
            $customerPhoneColumns = [];
            
            foreach ($header as $index => $columnName) {
                $trimmedName = trim($columnName);
                
                // Mark unwanted columns for removal
                if (in_array($trimmedName, $columnsToRemove)) {
                    $columnsToRemoveIndexes[] = $index;
                    $this->logger->info('Marking for removal: ' . $columnName . ' at index ' . $index);
                }
                
                // Track Customer Phone columns
                if ($trimmedName === 'Customer Phone') {
                    $customerPhoneColumns[] = $index;
                }
            }
            
            // If there are multiple Customer Phone columns, keep only the first one
            if (count($customerPhoneColumns) > 1) {
                for ($i = 1; $i < count($customerPhoneColumns); $i++) {
                    $columnsToRemoveIndexes[] = $customerPhoneColumns[$i];
                    $this->logger->info('Removing duplicate Customer Phone column at index: ' . $customerPhoneColumns[$i]);
                }
            }
            
            if (empty($columnsToRemoveIndexes)) {
                $this->logger->info('No unwanted columns found, fixing encoding only');
                $this->fixEncoding($lines, $directory, $fullPath);
                return;
            }
            
            // Create new header without unwanted columns
            $newHeader = [];
            foreach ($header as $index => $columnName) {
                if (!in_array($index, $columnsToRemoveIndexes)) {
                    $newHeader[] = $columnName;
                }
            }
            
            $csvLines = [];
            $csvLines[] = $this->createCsvLine($newHeader);
            
            // Process data rows
            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (empty($line)) {
                    continue;
                }
                
                $row = $this->parseCsvLine($line);
                $newRow = [];
                
                foreach ($row as $index => $value) {
                    if (!in_array($index, $columnsToRemoveIndexes)) {
                        $newRow[] = $value;
                    }
                }
                
                $csvLines[] = $this->createCsvLine($newRow);
            }
            
            // Write CSV with proper UTF-8 encoding
            $csvContent = implode("\n", $csvLines);
            $csvContent = "\xEF\xBB\xBF" . $csvContent;
            
            $directory->writeFile($fullPath, $csvContent);
            
            $this->logger->info('Successfully removed unwanted columns and fixed encoding');
            
        } catch (\Exception $e) {
            $this->logger->error('Error removing unwanted columns: ' . $e->getMessage());
        }
    }
    
    /**
     * Add customer note column if missing
     */
    protected function addCustomerNoteColumn($lines, $directory, $fullPath)
    {
        try {
            $header = $this->parseCsvLine($lines[0]);
            
            // التحقق من وجود عمود ملاحظات العميل
            if (in_array('ملاحظات العميل', $header)) {
                $this->logger->info('Customer note column already exists');
                $this->fixEncoding($lines, $directory, $fullPath);
                return;
            }
            
            // إضافة عمود ملاحظات العميل
            $header[] = 'ملاحظات العميل';
            $csvLines = [];
            $csvLines[] = $this->createCsvLine($header);
            
            // معالجة صفوف البيانات
            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (empty($line)) {
                    continue;
                }
                
                $row = $this->parseCsvLine($line);
                $row[] = ''; // إضافة عمود فارغ لملاحظات العميل
                $csvLines[] = $this->createCsvLine($row);
            }
            
            // كتابة CSV مع ترميز UTF-8 صحيح
            $csvContent = implode("\n", $csvLines);
            
            // إضافة BOM للعرض الصحيح في Excel
            $csvContent = "\xEF\xBB\xBF" . $csvContent;
            
            $directory->writeFile($fullPath, $csvContent);
            
            $this->logger->info('Successfully added customer note column and fixed encoding');
            
        } catch (\Exception $e) {
            $this->logger->error('Error adding customer note column: ' . $e->getMessage());
        }
    }
    
    /**
     * Fix encoding without adding columns
     */
    protected function fixEncoding($lines, $directory, $fullPath)
    {
        try {
            $csvLines = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                
                $row = $this->parseCsvLine($line);
                $csvLines[] = $this->createCsvLine($row);
            }
            
            // كتابة CSV مع ترميز UTF-8 صحيح
            $csvContent = implode("\n", $csvLines);
            
            // إضافة BOM للعرض الصحيح في Excel
            $csvContent = "\xEF\xBB\xBF" . $csvContent;
            
            $directory->writeFile($fullPath, $csvContent);
            
            $this->logger->info('Successfully fixed encoding');
            
        } catch (\Exception $e) {
            $this->logger->error('Error fixing encoding: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse CSV line properly
     */
    private function parseCsvLine($line)
    {
        return str_getcsv($line);
    }
    
    /**
     * Create CSV line with proper encoding
     */
    private function createCsvLine($row)
    {
        $csvRow = [];
        foreach ($row as $field) {
            $field = (string)$field;
            // تحويل إلى UTF-8 إذا لم يكن كذلك
            if (!mb_check_encoding($field, 'UTF-8')) {
                $field = mb_convert_encoding($field, 'UTF-8', 'auto');
            }
            $field = str_replace('"', '""', $field);
            $csvRow[] = '"' . $field . '"';
        }
        return implode(',', $csvRow);
    }
}