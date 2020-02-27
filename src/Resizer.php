<?php

namespace levmorozov\phpresizer;

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

    const GRAVITY_CENTER = 1;
    const GRAVITY_SMART = 2;
    const GRAVITY_FOCAL = 3;

    const POSITION_NORTH = 1;
    const POSITION_NORTH_EAST = 2;
    const POSITION_EAST = 3;
    const POSITION_SOUTH_EAST = 4;
    const POSITION_SOUTH = 5;
    const POSITION_SOUTH_WEST = 6;
    const POSITION_WEST = 7;
    const POSITION_NORTH_WEST = 8;
    const POSITION_CENTER = 9;

    const FILTER_SHARPEN = 's';

    protected $storage = 'local';

    /** @var S3 */
    protected $s3;
    protected $region = 'eu-central-1';
    protected $bucket = '';
    protected $key = '';
    protected $secret = '';
    protected $endpoint;

    protected $base_path;
    protected $path;

    public $resize = false;
    public $mode = Resizer::MODE_CONTAIN;
    public int $width;
    public int $height;
    public bool $crop = false;
    public int $crop_x;
    public int $crop_y;
    public int $crop_width;
    public int $crop_height;
    public int $quality = 80;
    public $gravity = Resizer::GRAVITY_CENTER;
    public int $gravity_x;
    public int $gravity_y;
    public array $background = [255, 255, 255];
    public array $watermarks = [];
    public array $filters = [];
    public ?int $pixel_ratio = null;
    public bool $auto_webp = true;
    public int $tag;

    public array $tags = [];

    protected $log;

    protected $decoder;

    protected $cache_path;

    public function __construct($config, UrlDecoder $decoder = null)
    {

        $this->config($config);

        if ($decoder === null) {
            $this->decoder = new UrlDecoder();
        } else {
            $this->decoder = $decoder;
        }

        try {
            $opts = $this->decoder->decode();

            if (!empty($this->tags) AND isset($opts['tag'])) {

                if (!isset($this->tags[$opts['tag']])) {
                    throw new \Exception("Unknown tag: " . $opts['tag']);
                }

                $this->config($this->tags[$opts['tag']]);
            }

            foreach ($opts as $key => $value)
                $this->$key = $value;


        } catch (\Throwable $e) {
            $this->error($e);
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
            exit;
        }
    }

    public function config(array $options)
    {
        foreach ($options as $key => $value)
            $this->$key = $value;
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
                \header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not found', true, 404);
            }
        } catch (\Throwable $e) {
            $this->error($e);
            \header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
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
        return $this->storage == 's3'
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