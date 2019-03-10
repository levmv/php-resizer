<?php

namespace levmorozov\phpresizer;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Jcupitt\Vips\BlendMode;
use Jcupitt\Vips\Image;
use Jcupitt\Vips\Interesting;
use Jcupitt\Vips\Size;

class Resizer
{
    const MODE_CONTAIN = 'contain';
    const MODE_FILL = 'fill';
    const MODE_CROP = 'crop';

    const GRAVITY_CENTER = 'center';
    const GRAVITY_SMART = 'smart';
    const GRAVITY_FOCAL = 'focal';

    const POSITION_NORTH = 'n';
    const POSITION_NORTH_EAST = 'ne';
    const POSITION_EAST = 'e';
    const POSITION_SOUTH_EAST = 'se';
    const POSITION_SOUTH = 's';
    const POSITION_SOUTH_WEST = 'sw';
    const POSITION_WEST = 'w';
    const POSITION_NORTH_WEST = 'nw';
    const POSITION_CENTER = 'c';

    const FILTER_SHARPEN = 's';

    protected $uri;

    protected $storage = 'local';

    /** @var S3Client */
    protected $s3;
    protected $region = 'eu-central-1';
    protected $bucket = '';
    protected $key = '';
    protected $secret = '';
    protected $endpoint;

    protected $base_path;
    protected $path;

    protected $resize;
    protected $mode = Resizer::MODE_CONTAIN;
    protected $width;
    protected $height;

    protected $crop;
    protected $crop_x;
    protected $crop_y;
    protected $crop_width;
    protected $crop_height;

    protected $quality;

    protected $gravity = Resizer::GRAVITY_CENTER;
    protected $gravity_x;
    protected $gravity_y;

    protected $background = [255, 255, 255];

    protected $watermarks = [];

    protected $filters = [];

    protected $pixel_ratio;

    protected $auto_webp = true;

    protected $log;

    protected $cache_path;

    public function __construct($config)
    {
        foreach ($config as $key => $value)
            $this->$key = $value;

        if ($this->storage == 's3') {

            $credentials = new Credentials($this->key, $this->secret);

            $args = [
                'version' => 'latest',
                'region' => $this->region,
                'credentials' => $credentials
            ];

            if($this->endpoint)
                $args['endpoint'] = $this->endpoint;

            $this->s3 = new S3Client($args);
        }

        if (!$this->uri)
            $this->parse_uri();

        $this->uri = ltrim($this->uri, '/');

        try {
            $this->parse_options();
        } catch (\Throwable $e) {
            $this->error($e);
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
            exit;
        }
    }

    protected function parse_uri()
    {
        $uri = $_SERVER['REQUEST_URI'];

        if ($uri !== '' && $uri[0] !== '/') {
            $uri = preg_replace('/^(http|https):\/\/[^\/]+/i', '', $uri);
        }

        if ($request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
            $uri = $request_uri;
        }

        $this->uri = $uri;
    }

    protected function parse_options()
    {
        $parts = explode('/', $this->uri, 2);

        $this->path = ltrim(urldecode($parts[1]), '/');

        foreach (explode(',', strtolower($parts[0])) as $option) {

            $name = substr($option, 0, 1);
            $value = substr($option, 1);

            if (!$value)
                continue;

            switch ($name) {
                case 'r':
                    if ($value[0] == 'f') {
                        $this->mode = Resizer::MODE_FILL;
                        $value = substr($value, 1);
                    } elseif ($value[0] == 'c') {
                        $this->mode = Resizer::MODE_CROP;
                        $value = substr($value, 1);
                    }
                    $sizes = explode('x', $value);
                    if (count($sizes) == 2) {
                        $this->resize = true;
                        $this->width = (int)$sizes[0];
                        $this->height = (int)$sizes[1];
                    } elseif (count($sizes) == 1 AND $sizes[0]) {
                        $this->resize = true;
                        $this->width = (int)$sizes[0];
                    }
                    break;
                case 'c':
                    $numbers = explode('x', $value);
                    if (count($numbers) == 4) {
                        $this->crop = true;
                        $this->crop_x = (int)$numbers[0];
                        $this->crop_y = (int)$numbers[1];
                        $this->crop_width = (int)$numbers[2];
                        $this->crop_height = (int)$numbers[3];
                    }
                    break;
                case 'q':
                    $this->quality = (int)$value;
                    break;
                case 'g':
                    if ($value[0] == 'f') {
                        $this->gravity = Resizer::GRAVITY_FOCAL;
                        $point = explode('x', substr($value, 1));
                        if (count($point) == 2) {
                            $this->gravity_x = (int)$point[0];
                            $this->gravity_y = (int)$point[1];
                        }
                    } elseif ($value[0] == 's') {
                        $this->gravity = Resizer::GRAVITY_SMART;
                    }
                    break;
                case 'b':
                    $this->background = sscanf($value, "%02x%02x%02x");
                    break;
                case 'w':
                    $opts = explode('-', $value);
                    if (count($opts) == 3 AND $opts[2]) {
                        $this->watermarks[] = [
                            'path' => urldecode($opts[2]),
                            'position' => $opts[0] ? $opts[0] : Resizer::POSITION_SOUTH_EAST,
                            'size' => $opts[1] ? (int)$opts[1] : 100
                        ];
                    }
                    break;
                case 'f':
                    if ($value)
                        $this->filters[] = $value;
                    break;
                case 'p':
                    $this->pixel_ratio = (float)$value;
                    break;
                case 'a':
                    $this->auto_webp = true;
                    break;
            }
        }
    }

    protected function error($e) : void
    {
        if (!$this->log)
            return;

        $refferer = $_SERVER['HTTP_REFERER'] ?? "";

        if ($e instanceof \Throwable)
            $e = (string) $e . "\n";

        if (($f = fopen($this->log, 'a')) === false) {
            throw new ErrorException("Unable to append to log file: {$this->log}");
        }
        flock($f, LOCK_EX);
        fwrite($f, '[' . date('Y-m-d H:i:s') . '] [' . $refferer . '] ' . $this->uri . ' ' . $e . "\n");
        flock($f, LOCK_UN);
        fclose($f);
    }

    public function process()
    {
        try {
            $this->resize();
        } catch (\Throwable $e) {
            $this->error($e);
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
            exit;
        }
    }

    protected function resize()
    {
        $file = $this->get_file($this->path);
        $image = Image::newFromBuffer($file);

        if ($this->crop) {

            if ($this->crop_x + $this->crop_width > $image->width)
                $this->crop_width = $image->width - $this->crop_x;
            if ($this->crop_y + $this->crop_height > $image->height)
                $this->crop_height = $image->height - $this->crop_y;

            $image = $image->crop($this->crop_x, $this->crop_y, $this->crop_width, $this->crop_height);
        }

        if ($this->pixel_ratio) {
            $this->width *= $this->pixel_ratio;
            $this->height *= $this->pixel_ratio;
        }

        if ($this->resize) {

            $final_width = $this->width;
            $final_height = $this->height;

            $options = ['height' => $this->height, 'size' => Size::DOWN];

            // TODO: Focal point gravity
            if ($this->mode == Resizer::MODE_CROP) {
                if ($this->gravity == Resizer::GRAVITY_CENTER) {
                    $options['crop'] = Interesting::CENTRE;
                } elseif ($this->gravity == Resizer::GRAVITY_SMART) {
                    $options['crop'] = Interesting::ATTENTION;
                }

                if ($image->width < $this->width OR $image->height < $this->height) {

                    $this->error("Bad crop area for resize (fit mode = crop)");

                    $this->mode = Resizer::MODE_FILL;

                    if ($image->height < $this->height)
                        $options['height'] = $image->height;

                    if ($image->width < $this->width)
                        $this->width = $image->width;
                }
            }

            $image = $image->thumbnail_image($this->width, $options);

            if ($this->mode == Resizer::MODE_FILL) {

                if ($image->hasAlpha())
                    $image = $image->flatten();

                $image = $image->embed(
                    ($final_width - $image->width) / 2,
                    ($final_height - $image->height) / 2,
                    $final_width,
                    $final_height,
                    ['background' => $this->background]
                );
            }
        }

        if (count($this->filters)) {

            foreach ($this->filters as $filter) {

                switch ($filter) {
                    case Resizer::FILTER_SHARPEN:
                        $image = $image->sharpen();
                        break;
                }
            }
        }

        if (count($this->watermarks)) {

            foreach ($this->watermarks as $watermark) {

                $mark = Image::pngload_buffer($this->get_file($watermark['path']));

                if ($watermark['size'] < 100)
                    $mark = $mark->resize($watermark['size'] / 100);

                switch ($watermark['position']) {
                    case Resizer::POSITION_NORTH:
                        $mark = $mark->embed($image->width / 2 - $mark->width / 2, 0, $image->width, $image->height);
                        break;
                    case Resizer::POSITION_NORTH_EAST:
                        $mark = $mark->embed($image->width - $mark->width, 0, $image->width, $image->height);
                        break;
                    case Resizer::POSITION_EAST:
                        $mark = $mark->embed($image->width - $mark->width, $image->height / 2 - $mark->height / 2, $image->width, $image->height);
                        break;
                    case Resizer::POSITION_SOUTH_EAST:
                        $mark = $mark->embed($image->width - $mark->width, $image->height - $mark->height, $image->width, $image->height);
                        break;
                    case Resizer::POSITION_SOUTH:
                        $mark = $mark->embed($image->width / 2 - $mark->width / 2, $image->height - $mark->height, $image->width, $image->height);
                        break;
                    case Resizer::POSITION_SOUTH_WEST:
                        $mark = $mark->embed(0, $image->height - $mark->height, $image->width, $image->height);
                        break;
                    case Resizer::POSITION_WEST:
                        $mark = $mark->embed(0, $image->height / 2 - $mark->height / 2, $image->width, $image->height);
                        break;
                    case Resizer::POSITION_NORTH_WEST:
                        $mark = $mark->embed(0, 0, $image->width, $image->height);
                        break;
                    case Resizer::POSITION_CENTER:
                        $mark = $mark->embed($image->width / 2 - $mark->width / 2, $image->height / 2 - $mark->height / 2, $image->width, $image->height);
                        break;
                }

                $image = $image->composite($mark, [BlendMode::OVER]);
            }
        }

        $params = [];

        if ($this->quality)
            $params['Q'] = $this->quality;

        if (isset($_SERVER['HTTP_ACCEPT']) AND strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false AND $this->auto_webp) {
            header('Content-type: image/webp');
            echo $image->webpsave_buffer($params);
        } else {
            header('Content-type: image/jpeg');
            echo $image->jpegsave_buffer($params);
        }
    }

    protected function get_file($path)
    {
        return $this->storage == 's3'
            ? $this->get_s3($path)
            : $this->get_local($path);
    }

    protected function get_local($path)
    {
        return file_get_contents($this->base_path . $path);
    }

    protected function get_s3($path)
    {
        if($this->cache_path && $object = $this->get_cached($path)) {
            return $object;
        }

        $object = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $path
        ]);

        if($this->cache_path) {
            $this->cache($path, $object['Body']);
        }

        return $object['Body'];
    }

    // Temporary:

    private function get_cached($path)
    {
        $filename = $this->cached_file($path);
        if(file_exists($filename)) {
            touch($filename);
            return file_get_contents($filename);
        }

        return false;
    }

    private function cache($path, $content)
    {
        $filename = $this->cached_file($path);

        $dir = dirname($filename);
        if(!is_dir($dir))
            mkdir($dir,0777, true);

        file_put_contents($filename, $content);
        chmod($filename, 0666); // todo: make it configurable with 0644 by default
    }

    private function cached_file($path) {
        $file = md5($path);
        $prefix = substr($file,0,2);
        return rtrim($this->cache_path,'/')."/$prefix/$file";
    }
}