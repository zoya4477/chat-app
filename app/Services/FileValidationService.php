<?php
// app/Services/FileValidationService.php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileValidationService
{
    // Allowed file types with their MIME types
    protected $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'application/zip',
        'application/x-zip-compressed'
    ];

    // Allowed file extensions
    protected $allowedExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx',
        'txt', 'zip'
    ];

    // Max file size (10MB by default)
    protected $maxFileSize = 10485760; // 10MB in bytes

    public function __construct()
    {
        $this->maxFileSize = config('app.max_file_size', 10) * 1024 * 1024;
    }

    public function validate(UploadedFile $file)
    {
        $errors = [];

        // Check file size
        if ($file->getSize() > $this->maxFileSize) {
            $errors[] = sprintf(
                'File size exceeds limit of %s MB',
                $this->maxFileSize / 1024 / 1024
            );
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            $errors[] = 'File type not allowed. Allowed types: ' . implode(', ', $this->allowedExtensions);
        }

        // Check extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedExtensions)) {
            $errors[] = 'File extension not allowed';
        }

        // Scan for malware (basic)
        if (!$this->scanForMalware($file)) {
            $errors[] = 'File appears to be malicious';
        }

        // Check if file name contains suspicious patterns
        if ($this->containsSuspiciousPattern($file->getClientOriginalName())) {
            $errors[] = 'File name contains suspicious characters';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    protected function scanForMalware($file)
    {
        $content = file_get_contents($file->getRealPath());
        
        // Check for PHP tags
        if (preg_match('/<\?php/i', $content)) {
            return false;
        }
        
        // Check for JavaScript injections
        if (preg_match('/<script/i', $content)) {
            return false;
        }
        
        // Check for executable code
        $suspiciousPatterns = [
            'eval\s*\(',
            'base64_decode\s*\(',
            'system\s*\(',
            'exec\s*\(',
            'shell_exec\s*\(',
            'passthru\s*\(',
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $content)) {
                return false;
            }
        }
        
        return true;
    }

    protected function containsSuspiciousPattern($filename)
    {
        $suspicious = ['..', './', '\\', '%00', '\0', ';', '|', '&', '$', '`'];
        
        foreach ($suspicious as $pattern) {
            if (strpos($filename, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    public function sanitizeFileName($filename)
    {
        // Remove any path information
        $filename = basename($filename);
        
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        
        // Limit length
        $filename = substr($filename, 0, 255);
        
        // Generate unique name if needed
        if (empty($filename)) {
            $filename = uniqid() . '.bin';
        }
        
        return $filename;
    }
}