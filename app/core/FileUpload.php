<?php
/**
 * Secure File Upload Handler
 * Validates and processes file uploads safely
 */

namespace App\Core;

class FileUpload
{
    private const MAX_FILE_SIZE = 10485760; // 10 MB
    private const ALLOWED_EXTENSIONS = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg', 'image/jpg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
    ];

    /**
     * Validate and upload a file
     * 
     * @param array $file $_FILES array element
     * @param string $uploadDir Target directory
     * @param string $allowedType File type (pdf, image, etc.)
     * @param string|null $customFileName Custom filename (without extension)
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public static function upload(array $file, string $uploadDir, string $allowedType = 'pdf', ?string $customFileName = null): array
    {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'path' => null,
                'error' => self::getUploadErrorMessage($file['error'])
            ];
        }

        // Validate file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Die Datei ist zu groß. Maximale Größe: ' . (self::MAX_FILE_SIZE / 1048576) . ' MB'
            ];
        }

        // Get file extension
        $originalName = $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        // Validate extension based on type
        $allowedExtensions = self::getAllowedExtensions($allowedType);
        if (!in_array($extension, $allowedExtensions)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Ungültiger Dateityp. Erlaubt: ' . implode(', ', $allowedExtensions)
            ];
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimeTypes = self::getAllowedMimeTypes($allowedType);
        if (!in_array($mimeType, $allowedMimeTypes)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Ungültiger MIME-Typ. Die Datei scheint nicht vom erwarteten Typ zu sein.'
            ];
        }

        // Additional validation for PDFs
        if ($allowedType === 'pdf' && $extension === 'pdf') {
            $pdfValidation = self::validatePdf($file['tmp_name']);
            if (!$pdfValidation['valid']) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => $pdfValidation['error'] ?? 'Ungültige PDF-Datei'
                ];
            }
        }

        // Create upload directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Upload-Verzeichnis konnte nicht erstellt werden'
                ];
            }
        }

        // Generate safe filename
        if ($customFileName) {
            $fileName = self::sanitizeFileName($customFileName) . '.' . $extension;
        } else {
            $fileName = self::generateSafeFileName($extension);
        }

        $filePath = rtrim($uploadDir, '/') . '/' . $fileName;

        // Check if file already exists and add suffix if needed
        $counter = 1;
        $originalPath = $filePath;
        while (file_exists($filePath)) {
            $pathInfo = pathinfo($originalPath);
            $filePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
            $counter++;
        }

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Fehler beim Speichern der Datei'
            ];
        }

        // Set proper permissions
        chmod($filePath, 0644);

        return [
            'success' => true,
            'path' => $filePath,
            'filename' => basename($filePath),
            'error' => null
        ];
    }

    /**
     * Get allowed extensions for a file type
     */
    private static function getAllowedExtensions(string $type): array
    {
        switch ($type) {
            case 'pdf':
                return ['pdf'];
            case 'image':
                return ['jpg', 'jpeg', 'png', 'gif'];
            default:
                return [];
        }
    }

    /**
     * Get allowed MIME types for a file type
     */
    private static function getAllowedMimeTypes(string $type): array
    {
        $mimeTypes = [];
        
        switch ($type) {
            case 'pdf':
                return self::ALLOWED_EXTENSIONS['pdf'];
            case 'image':
                return array_merge(
                    self::ALLOWED_EXTENSIONS['jpg'],
                    self::ALLOWED_EXTENSIONS['jpeg'],
                    self::ALLOWED_EXTENSIONS['png'],
                    self::ALLOWED_EXTENSIONS['gif']
                );
            default:
                return [];
        }
    }

    /**
     * Validate PDF file
     */
    private static function validatePdf(string $filePath): array
    {
        // Check if file starts with PDF header
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return ['valid' => false, 'error' => 'Datei konnte nicht gelesen werden'];
        }

        $header = fread($handle, 4);
        fclose($handle);

        if ($header !== '%PDF') {
            return ['valid' => false, 'error' => 'Ungültige PDF-Datei'];
        }

        return ['valid' => true];
    }

    /**
     * Generate safe filename
     */
    private static function generateSafeFileName(string $extension): string
    {
        return date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    /**
     * Sanitize filename
     */
    private static function sanitizeFileName(string $fileName): string
    {
        // Remove any path components
        $fileName = basename($fileName);
        
        // Remove special characters, keep only alphanumeric, dash, underscore, and dot
        $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        
        // Remove multiple consecutive underscores
        $fileName = preg_replace('/_+/', '_', $fileName);
        
        // Limit length
        if (strlen($fileName) > 100) {
            $fileName = substr($fileName, 0, 100);
        }
        
        return $fileName;
    }

    /**
     * Get upload error message
     */
    private static function getUploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Die Datei ist zu groß.';
            case UPLOAD_ERR_PARTIAL:
                return 'Die Datei wurde nur teilweise hochgeladen.';
            case UPLOAD_ERR_NO_FILE:
                return 'Keine Datei wurde hochgeladen.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Temporäres Verzeichnis fehlt.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Fehler beim Schreiben der Datei.';
            case UPLOAD_ERR_EXTENSION:
                return 'Eine PHP-Erweiterung hat den Upload gestoppt.';
            default:
                return 'Unbekannter Upload-Fehler.';
        }
    }

    /**
     * Delete uploaded file
     */
    public static function delete(string $filePath): bool
    {
        if (file_exists($filePath) && is_file($filePath)) {
            return unlink($filePath);
        }
        return false;
    }
}

