<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Media extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected $appends = ['display_name', 'display_url'];


    /**
     * Get all of the owning mediable models.
     */
    public function mediable()
    {
        return $this->morphTo();
    }

    /**
     * Get display name for the media
     */
    public function getDisplayNameAttribute()
    {
        if (trim((string) $this->original_name) !== '') {
            return $this->original_name;
        }

        $array = explode('_', $this->file_name, 3);
        return !empty($array[2]) ? $array[2] : $array[1];
    }

    /**
     * Get display link for the media
     */
    public function getDisplayUrlAttribute()
    {
        $path = asset('/uploads/' . $this->storageDirectory() . '/' . rawurlencode($this->diskFileName()));

        return $path;
    }

    /**
     * Get display path for the media
     */
    public function getDisplayPathAttribute()
    {
        $path = public_path('uploads/' . $this->storageDirectory()) . DIRECTORY_SEPARATOR . $this->diskFileName();

        return $path;
    }

    private function storageDirectory(): string
    {
        return in_array($this->storage_location, ['media', 'documents'], true)
            ? $this->storage_location
            : 'media';
    }

    private function diskFileName(): string
    {
        return basename(str_replace('\\', '/', (string) $this->file_name));
    }

    public function getDescriptionLabelAttribute()
    {
        switch ($this->description) {
            case 'passport':
                return 'Passport';

            case 'national-id-front':
                return 'National ID Front';

            case 'national-id-back':
                return 'National ID Back';

            case 'driver-license':
                return 'Driver\'s License';

            default:
                return $this->description;
        }
    }

    public function getExtensionAttribute()
    {
        return pathinfo($this->display_url, PATHINFO_EXTENSION);
    }

    /**
     * Get display link for the media
     */
    public function thumbnail($size = [60, 60], $class = null)
    {
        $html = '<img';
        $html .= ' src="' . $this->display_url . '"';
        $html .= ' width="' . $size[0] . '"';
        $html .= ' height="' . $size[1] . '"';

        if (!empty($class)) {
            $html .= ' class="' . $class . '"';
        }

        $html .= '>';

        return $html;
    }

    /**
     * Uploads files from the request and add's medias to the supplied model.
     *
     * @param  int $business_id, obj $model, $obj $request, string $file_name
     */
    public static function uploadMedia($business_id, $model, $request, $file_name, $is_single = false, $model_media_type = null)
    {
        //If app environment is demo return null
        if (config('app.env') == 'demo') {
            return null;
        }

        if ($request->hasFile($file_name)) {
            $files = $request->file($file_name);
            $uploaded_files = [];

            //If multiple files present
            if (is_array($files)) {
                foreach ($files as $file) {
                    $uploaded_file = Media::uploadFile($file);

                    if (!empty($uploaded_file)) {
                        $uploaded_files[] = $uploaded_file;
                    }
                }
            } else {
                $uploaded_file = Media::uploadFile($files);
                if (!empty($uploaded_file)) {
                    $uploaded_files[] = $uploaded_file;
                }
            }

            //If one to one relationship upload single file
            if ($is_single) {
                $uploaded_files = $uploaded_files[0];
            }
            // attach media to model
            Media::attachMediaToModel($model, $business_id, $uploaded_files, $request, $model_media_type);
        }
    }

    /**
     * Uploads requested file to storage.
     *
     */
    public static function uploadFile($file)
    {
        if (! $file || ! $file->isValid()) {
            return null;
        }

        if ($file->getSize() > config('constants.document_size_limit')) {
            throw new \Exception($file->getClientOriginalName() . ' is too large try a different image or a screenshot of the original');
        }

        self::assertSafeUpload($file);

        $originalName = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeOriginalName = self::sanitizeFileName($originalName) ?: 'upload';
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $new_file_name = time() . '_' . Str::random(20) . '_' . $safeOriginalName;
        if ($extension !== '') {
            $new_file_name .= '.' . $extension;
        }

        if ($file->storeAs('/media', $new_file_name)) {
            return $new_file_name;
        }

        return null;
    }

    private static function assertSafeUpload($file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $blockedExtensions = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php74', 'php8', 'phtml', 'phar',
            'inc', 'cgi', 'pl', 'py', 'sh', 'bash', 'asp', 'aspx', 'jsp',
            'htaccess', 'ini', 'html', 'htm', 'js', 'mjs', 'svg',
        ];

        if (in_array($extension, $blockedExtensions, true)) {
            throw new \InvalidArgumentException('Executable or active-content uploads are not allowed.');
        }

        $mime = strtolower((string) $file->getMimeType());
        $blockedMimes = [
            'application/x-php', 'text/x-php', 'text/html', 'application/xhtml+xml',
            'application/javascript', 'text/javascript', 'image/svg+xml',
        ];

        if (in_array($mime, $blockedMimes, true) || str_contains($mime, 'php')) {
            throw new \InvalidArgumentException('The uploaded file type is not allowed.');
        }
    }

    private static function sanitizeFileName($file_name)
    {
        // Remove anything which isn't a word, whitespace, number
        // or any of the following caracters -_~,;[]().
        // If you don't need to handle multi-byte characters
        // you can use preg_replace rather than mb_ereg_replace
        $new_file_name = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $file_name);
        // Remove any runs of periods
        $new_file_name = mb_ereg_replace("([\.]{2,})", '', $new_file_name);
        //Remove any whitespaces
        $new_file_name = preg_replace('/[[:space:]]+/', '-', $new_file_name);

        return $new_file_name;
    }

    /**
     * Deletes resource from database and storage
     *
     */
    public static function deleteMedia($business_id, $media_id)
    {
        $media = Media::where('business_id', $business_id)->findOrFail($media_id);

        if ($media->storage_location !== 'documents' && empty($media->legacy_source_attachment_id)) {
            $media_path = $media->display_path;
            if (file_exists($media_path)) {
                unlink($media_path);
            }
        }

        $media->delete();
    }

    public function uploaded_by_user()
    {
        return $this->belongsTo(\App\User::class, 'uploaded_by');
    }

    public static function attachMediaToModel($model, $business_id, $uploaded_files, $request = null, $model_media_type = null)
    {
        if (!empty($uploaded_files)) {
            if (is_array($uploaded_files)) {
                $media_obj = [];
                foreach ($uploaded_files as $value) {
                    $media_obj[] = new \Modules\Accounting\Entities\Media([
                        'file_name' => $value,
                        'business_id' => $business_id,
                        'description' => !empty($request->description) ? $request->description : null,
                        'uploaded_by' => !empty($request->uploaded_by) ? $request->uploaded_by : auth()->user()->id,
                        'model_media_type' => $model_media_type
                    ]);
                }

                $model->media()->saveMany($media_obj);
            } else {
                //delete previous media if exists
                $model->media()->delete();

                $media_obj = new \Modules\Accounting\Entities\Media([
                    'file_name' => $uploaded_files,
                    'business_id' => $business_id,
                    'description' => !empty($request->description) ? $request->description : null,
                    'uploaded_by' => !empty($request->uploaded_by) ? $request->uploaded_by : auth()->user()->id,
                    'model_media_type' => $model_media_type
                ]);
                $model->media()->save($media_obj);
            }
        }
    }
}
