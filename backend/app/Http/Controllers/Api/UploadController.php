<?php

/**
 * ============================================================
 * UPLOAD CONTROLLER — File Upload Handling
 * ============================================================
 * What this controller does:
 *   Handles uploading images and videos for products,
 *   categories, and other site content. Files are saved
 *   to Laravel's public storage folder so they can be
 *   accessed via a URL. Also supports deleting uploaded files.
 *
 * Supported file types: jpg, png, gif, webp, mp4, mov, avi
 * Max file size: 10 MB
 *
 * SECURITY: SVG is intentionally NOT allowed — SVGs can carry inline
 * <script> tags and are stored on the same origin as the customer
 * frontend, so a malicious upload would result in stored XSS. If SVG
 * is required in the future, sanitize via enshrined/svg-sanitize first.
 *
 * API endpoints:
 *   POST   /api/upload          — Upload a single file
 *   POST   /api/upload/multiple — Upload multiple files at once
 *   DELETE /api/upload/{filename} — Delete an uploaded file
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageResizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function __construct(private ImageResizer $imageResizer) {}

    /**
     * Post-process a freshly-stored upload.
     *
     * 1. Convert the ORIGINAL to sRGB. iPhone/Mac photos arrive tagged
     *    Display P3; browsers honour that profile but email clients ignore
     *    it and render the pixels over-saturated — and order emails link to
     *    the original, not to a variant. Converting has to happen before the
     *    variants are cut so they inherit the corrected colours.
     * 2. Generate the responsive WebP variants.
     *
     * Never throws: colour handling and resizing must not break an upload.
     */
    private function generateVariants(string $storedPath): void
    {
        $absolute = Storage::disk('public')->path($storedPath);

        $this->imageResizer->convertFileToSrgb($absolute);
        $this->imageResizer->generateVariants($absolute);
    }

    /**
     * Determines which folder to save the file in based on the "type" parameter.
     * For example, type="product" saves to the "products" folder.
     */
    private function resolveFolder(Request $request): string
    {
        // Check query param first, then form data field
        $type = $request->query('type') ?? $request->input('folder', 'assets');

        return match ($type) {
            'product', 'products' => 'products',
            'category', 'categories' => 'categories',
            'video', 'videos' => 'videos',
            'logo', 'logos' => 'logos',
            default => 'assets',
        };
    }

    /**
     * POST /api/upload
     * Uploads a single file (image or video).
     * Returns the public URL, filename, and file size.
     */
    public function upload(Request $request)
    {
        $folder = $this->resolveFolder($request);

        // Store images (logos, favicon, hero/banner art) are still images only —
        // PNG/JPG/WebP, matching what the admin Branding tab accepts. Anything
        // else may be any image or video. 10MB cap; svg dropped (XSS risk).
        // Videos get their own limit: a 30-second phone clip is well over 10MB.
        // 60MB stays under PHP's 64MB upload limit on this server.
        $mimes = match ($folder) {
            'logos' => 'jpg,jpeg,png,webp',
            'videos' => 'mp4,m4v,mov,webm',
            default => 'jpg,jpeg,png,gif,webp,mp4,mov,avi',
        };
        $maxKb = $folder === 'videos' ? 61440 : 10240;
        $request->validate(['file' => "required|file|mimes:{$mimes}|max:{$maxKb}"]);

        $path = $request->file('file')->store("uploads/{$folder}", 'public');
        $this->generateVariants($path);
        // Branding images and videos are stored root-relative, so a store
        // installed on a temporary domain keeps them after moving to the real
        // one. Product/category images stay absolute: emails embed them as-is.
        $url = in_array($folder, ['logos', 'videos'], true)
            ? '/storage/'.$path
            : asset("storage/{$path}");

        return response()->json([
            'success' => true,
            'url' => $url,
            'filename' => basename($path),
            'size' => $request->file('file')->getSize(),
        ]);
    }

    /**
     * POST /api/upload/multiple
     * Uploads multiple files at once.
     * Returns an array of uploaded file details (URL, filename, size).
     */
    public function uploadMultiple(Request $request)
    {
        $request->validate(['files.*' => 'required|file|mimes:jpg,jpeg,png,gif,webp,mp4,mov,avi|max:10240']);

        $folder = $this->resolveFolder($request);

        $uploaded = [];
        foreach ($request->file('files') as $file) {
            $path = $file->store("uploads/{$folder}", 'public');
            $this->generateVariants($path);
            $uploaded[] = [
                'url' => asset("storage/{$path}"),
                'filename' => basename($path),
                'size' => $file->getSize(),
            ];
        }

        return response()->json(['success' => true, 'files' => $uploaded]);
    }

    /**
     * DELETE /api/upload/{filename}
     * Deletes a previously uploaded file from storage.
     * The filename is sanitized to prevent accessing files outside the uploads folder.
     */
    public function destroy(Request $request, string $filename)
    {
        // Sanitize filename to prevent path traversal
        $filename = basename($filename);

        $folder = $this->resolveFolder($request);

        $path = "uploads/{$folder}/{$filename}";

        if (Storage::disk('public')->exists($path)) {
            $this->imageResizer->deleteVariants(Storage::disk('public')->path($path));
            Storage::disk('public')->delete($path);

            return response()->json(['success' => true]);
        }

        return response()->json(['error' => 'File not found'], 404);
    }
}
