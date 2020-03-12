<?php

namespace levmorozov\phpresizer;

use Jcupitt\Vips\Access;
use Jcupitt\Vips\BlendMode;
use Jcupitt\Vips\Image;
use Jcupitt\Vips\Interesting;
use Jcupitt\Vips\Size;
use levmorozov\s3\S3;

class Resizer
{
    const DPR_1_5 = 1;
    const DPR_TWO = 2;
    const DPR_THREE = 3;

    const MODE_CONTAIN = 1;
    const MODE_FILL = 2;
    const MODE_CROP = 3;

    public const GRAVITY_CENTER = 1;
    public const GRAVITY_SMART = 2;
    public const GRAVITY_FOCAL = 3;

    public const POSITION_NORTH = 'n';
    public const POSITION_NORTH_EAST = 'ne';
    public const POSITION_EAST = 'e';
    public const POSITION_SOUTH_EAST = 'se';
    public const POSITION_SOUTH = 's';
    public const POSITION_SOUTH_WEST = 'sw';
    public const POSITION_WEST = 'w';
    public const POSITION_NORTH_WEST = 'nw';
    public const POSITION_CENTER = 'c';

    const FILTER_SHARPEN = 's';

    protected bool $remote_storage = false;

    /** @var S3 */
    protected S3 $s3;
    protected string $region = 'eu-central-1';
    protected string $bucket = '';
    protected string $key = '';
    protected string $secret = '';
    protected string $endpoint = '';

    protected string $base_path = '';
    protected string $path = '';

    public bool $presets_only = false;

    public bool $resize = false;
    public int $mode = Resizer::MODE_CONTAIN;
    public int $width;
    public int $height;
    public bool $crop = false;
    public int $crop_x;
    public int $crop_y;
    public int $crop_width;
    public int $crop_height;
    public int $quality = 80;
    public int $gravity = Resizer::GRAVITY_CENTER;
    public int $gravity_x;
    public int $gravity_y;
    public array $background = [255, 255, 255];
    public array $watermarks = [];
    public array $filters = [];
    public ?int $pixel_ratio = null;
    public bool $auto_webp = true;

    public array $presets = [];
    public array $request_presets = [];

    protected $log;

    protected string $cache_path = '';

    public function __construct($config)
    {
        $this->config($config);
    }

    public function config(array $options)
    {
        foreach ($options as $key => $value)
            $this->$key = $value;
    }

    public function decode(string $uri = null): void
    {
        if ($uri === null) {
            $uri = \ltrim($_SERVER['REQUEST_URI'], '/');
        }

        try {
            $this->decode_params($uri);

            if (!empty($this->request_presets)) {

                foreach($this->request_presets as $preset_name) {
                    if (!isset($this->presets[$preset_name])) {
                        throw new \Exception("Unknown preset: $preset_name");
                    }
                }

                $this->config($this->presets[$preset_name]);
            }


        } catch (\Throwable $e) {
            $this->error($e);
            http_response_code(500);
            exit;
        }
    }

    /**
     * Returns array [params, path]
     *
     * @param string $uri
     * @return array
     */
    public function split_uri(string $uri) : array
    {
        $parts =  \explode('/', $uri, 2);
        $parts[1] = \ltrim(\urldecode($parts[1]), '/');

        return $parts;
    }

    protected function decode_params(string $uri) : void
    {
        $parts = $this->split_uri($uri);

        $this->path = $parts[1];

        if($this->presets_only) {
            $presets = \explode(',', $parts[0]);
            if(empty($presets))
                throw new \Exception('No one preset found');
            foreach ($presets as $option) {
                $this->request_presets[] = $option;
            }

            return;
        }

        foreach (\explode(',', $parts[0]) as $option) {

            $name = \substr($option, 0, 1);
            $value = \substr($option, 1);

            if (!$value) {
                throw new \Exception('Wrong settings. Empty value');
            }

            switch ($name) {
                case 'r':
                    if ($value[0] === 'f') {
                        $this->mode = Resizer::MODE_FILL;
                        $value = \substr($value, 1);
                    } elseif ($value[0] === 'c') {
                        $this->mode = Resizer::MODE_CROP;
                        $value = \substr($value, 1);
                    }
                    $sizes = explode('x', $value);
                    if (\count($sizes) === 2) {
                        $this->resize = true;
                        $this->width = (int)$sizes[0];
                        $this->height = (int)$sizes[1];
                    } elseif (\count($sizes) === 1 AND $sizes[0]) {
                        $this->resize = true;
                        $this->width = (int)$sizes[0];
                    }
                    break;
                case 'c':
                    $numbers = \explode('x', $value);
                    if (\count($numbers) === 4) {
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
                    if ($value[0] === 'f') {
                        $this->gravity = Resizer::GRAVITY_FOCAL;
                        $point = explode('x', substr($value, 1));
                        if (count($point) === 2) {
                            $this->gravity_x = (int)$point[0];
                            $this->gravity_y = (int)$point[1];
                        }
                    } elseif ($value[0] === 's') {
                        $this->gravity = Resizer::GRAVITY_SMART;
                    }
                    break;
                case 'b':
                    $this->background = \sscanf($value, "%02x%02x%02x");
                    break;
                case 'w':
                    $opts = \explode('-', $value);
                    if (\count($opts) === 3 AND $opts[2]) {
                        $this->watermarks[] = [
                            'path' => \urldecode($opts[2]),
                            'position' => $opts[0] ? $opts[0] : Resizer::POSITION_SOUTH_EAST,
                            'size' => $opts[1] ? (int)$opts[1] : 100
                        ];
                    }
                    break;
                case 'f':
                    $this->filters[] = $value;
                    break;
                case 'p':
                    switch ($value) {
                        case '2':
                            $this->pixel_ratio = self::DPR_TWO;
                            break;
                        case '1.5':
                            $this->pixel_ratio = self::DPR_1_5;
                            break;
                        case '3':
                            $this->pixel_ratio = self::DPR_THREE;
                            break;
                    }
                    break;
                case 's':
                case 't':
                    $this->request_presets[] = $value;
                    break;
                case 'n':
                    break;
                default:
                    throw new \Exception("Unsupported param $name");
            }
        }
    }
    protected function error($e): void
    {
        if (!$this->log)
            return;

        $refferer = $_SERVER['HTTP_REFERER'] ?? "";

        if ($e instanceof \Throwable)
            $e = (string)$e . "\n";

        if (($f = fopen($this->log, 'a')) === false) {
            throw new \ErrorException("Unable to append to log file: {$this->log}");
        }
        flock($f, LOCK_EX);
        fwrite($f, '[' . date('Y-m-d H:i:s') . '] [' . $refferer . '] ' . $_SERVER['REQUEST_URI'] . ' ' . $e . "\n");
        flock($f, LOCK_UN);
        fclose($f);
    }

    public function process()
    {
        try {
            if ($this->resize() === false) {
                http_response_code(404);
            }
        } catch (\Throwable $e) {
            $this->error($e);
            http_response_code(500);
            exit;
        }
    }

    protected function resize()
    {

        $file = $this->get_file($this->path);
        if (!$file) {
            return false;
        }

        \vips_cache_set_max(0);

        $image = Image::newFromBuffer($file, '', [
            'access' => 'sequential',
        ]);

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

        if (\count($this->filters)) {

            foreach ($this->filters as $filter) {

                switch ($filter) {
                    case Resizer::FILTER_SHARPEN:
                        $image = $image->sharpen([
                            'sigma' => 0.5,
                            'x1' => 2,
                            'y2' => 10,
                            'y3' => 20,
                            'm1' => 0,
                            'm2' => 2
                        ]);
                        break;
                }
            }
        }

        if (\count($this->watermarks)) {

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

        if (isset($_SERVER['HTTP_ACCEPT']) AND strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false AND $this->auto_webp) {

            $params['Q'] = $this->quality + $this->webp_q_correction;

            \header('Content-type: image/webp');
            echo $image->webpsave_buffer($params);
        } else {

            $params['Q'] = $this->quality;

            \header('Content-type: image/jpeg');
            echo $image->jpegsave_buffer($params);
        }
        return true;
    }

    protected function get_file($path)
    {
        return $this->remote_storage
            ? $this->get_s3($path)
            : $this->get_local($path);
    }

    protected function get_local($path)
    {
        return \file_get_contents($this->base_path . $path);
    }

    protected function get_s3($path)
    {
        if ($this->cache_path && $object = $this->get_cached($path)) {
            return $object;
        }

        if (!$this->s3) {
            $this->s3 = new S3($this->key, $this->secret, $this->endpoint, $this->region);
        }


        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $path
        ]);

        if ($result['error']) {
            if ($result['error']['code'] === 'NoSuchKey')
                return false;

            throw new \Exception($result['error']['code'] . ': ' . $result['error']['message']);
        }

        if ($this->cache_path) {
            $this->cache($path, $result['body']);
        }

        return $result['body'];
    }

    // Temporary:

    private function get_cached($path)
    {
        $filename = $this->cached_file($path);
        if (\file_exists($filename)) {
            \exec("touch {$filename}");
            return \file_get_contents($filename);
        }

        return false;
    }

    private function cache($path, $content)
    {
        $filename = $this->cached_file($path);

        $dir = \dirname($filename);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }

        \file_put_contents($filename, $content);
        \chmod($filename, 0666); // todo: make it configurable with 0644 by default
    }

    private function cached_file($path)
    {
        $file = \md5($path);
        $prefix = \substr($file, 0, 2);
        return \rtrim($this->cache_path, '/') . "/$prefix/$file";
    }
}