<?php

namespace levmorozov\phpresizer;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Jcupitt\Vips\BlendMode;
use Jcupitt\Vips\Image;
use Jcupitt\Vips\Interesting;

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

    protected $file;

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

    protected $pixel_density_ratio;

    protected $log;

    public function __construct($config)
    {
        foreach ($config as $key => $value)
            $this->$key = $value;

        if ($this->storage == 's3') {

            $credentials = new Credentials($this->key, $this->secret);

            $this->s3 = new S3Client([
                'version' => 'latest',
                'region' => $this->region,
                'credentials' => $credentials
            ]);
        }

        if (!$this->uri)
            $this->parse_uri();

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

        $uri = rawurldecode($uri);

        if (strpos($uri, '/') === 0)
            $uri = substr($uri, 1);

        $this->uri = $uri;
    }

    protected function parse_options()
    {
        $parts = explode('/', $this->uri, 2);

        $this->file = $this->get_file($parts[1]);

        foreach (explode(',', strtolower($parts[0])) as $option) {

            $name = substr($option, 0, 1);
            $value = substr($option, 1);

            if (!$value)
                continue;

            switch ($name) {
                case 'r':
                    if ($value[0] == 'f') {
                        $this->mode = $this::MODE_FILL;
                        $value = substr($value, 1);
                    } elseif ($value[0] == 'c') {
                        $this->mode = $this::MODE_CROP;
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
                        $this->gravity = $this::GRAVITY_FOCAL;
                        $point = explode('x', substr($value, 1));
                        if (count($point) == 2) {
                            $this->gravity_x = (int)$point[0];
                            $this->gravity_y = (int)$point[1];
                        }
                    } elseif ($value[0] == 's') {
                        $this->gravity = $this::GRAVITY_SMART;
                    }
                    break;
                case 'b':
                    $this->background = sscanf($value, "%02x%02x%02x");
                    break;
                case 'w':
                    $opts = explode('-', $value);
                    if (count($opts) == 3 AND $opts[2]) {
                        $this->watermarks[] = [
                            'path' => $opts[2],
                            'position' => $opts[0] ? $opts[0] : $this::POSITION_SOUTH_EAST,
                            'size' => $opts[1] ? (int)$opts[1] : 100
                        ];
                    }
                    break;
                case 'f':
                    if ($value)
                        $this->filters[] = $value;
                    break;
                case 'p':
                    $this->pixel_density_ratio = (float)$value;
                    break;
            }
        }
    }

    protected function error($e)
    {
        if (!$this->log)
            return;
        $f = fopen(__DIR__ . '/' . $this->log, 'a');
        fwrite($f, '[' . date('Y-m-d H:i:s') . '] ' . $this->uri . ' ' . $e . "\n\n\n");
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
        $image = Image::jpegload_buffer($this->file);

        if ($this->crop) {

            if ($this->crop_x + $this->crop_width > $image->width)
                $this->crop_width = $image->width - $this->crop_x;
            if ($this->crop_y + $this->crop_height > $image->height)
                $this->crop_height = $image->height - $this->crop_y;

            $image = $image->crop($this->crop_x, $this->crop_y, $this->crop_width, $this->crop_height);
        }

        if ($this->pixel_density_ratio) {
            $this->width *= $this->pixel_density_ratio;
            $this->height *= $this->pixel_density_ratio;
        }

        if ($this->resize AND $this->width <= $image->width AND $this->height <= $image->height) {

            $options = ['height' => $this->height];

            // TODO: Focal point gravity
            if ($this->mode == $this::MODE_CROP) {
                if ($this->gravity == $this::GRAVITY_CENTER) {
                    $options['crop'] = Interesting::CENTRE;
                } elseif ($this->gravity == $this::GRAVITY_SMART) {
                    $options['crop'] = Interesting::ATTENTION;
                }
            }

            $image = Image::thumbnail_buffer($image->jpegsave_buffer(), $this->width, $options);

            if ($this->mode == $this::MODE_FILL) {
                $image = $image->embed(
                    ($this->width - $image->width) / 2,
                    ($this->height - $image->height) / 2,
                    $this->width,
                    $this->height,
                    ['background' => $this->background]
                );
            }
        }

        if (count($this->filters)) {

            foreach ($this->filters as $filter) {

                switch ($filter) {
                    case $this::FILTER_SHARPEN:
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
                    case $this::POSITION_NORTH:
                        $mark = $mark->embed($image->width / 2 - $mark->width / 2, 0, $image->width, $image->height);
                        break;
                    case $this::POSITION_NORTH_EAST:
                        $mark = $mark->embed($image->width - $mark->width, 0, $image->width, $image->height);
                        break;
                    case $this::POSITION_EAST:
                        $mark = $mark->embed($image->width - $mark->width, $image->height / 2 - $mark->height / 2, $image->width, $image->height);
                        break;
                    case $this::POSITION_SOUTH_EAST:
                        $mark = $mark->embed($image->width - $mark->width, $image->height - $mark->height, $image->width, $image->height);
                        break;
                    case $this::POSITION_SOUTH:
                        $mark = $mark->embed($image->width / 2 - $mark->width / 2, $image->height - $mark->height, $image->width, $image->height);
                        break;
                    case $this::POSITION_SOUTH_WEST:
                        $mark = $mark->embed(0, $image->height - $mark->height, $image->width, $image->height);
                        break;
                    case $this::POSITION_WEST:
                        $mark = $mark->embed(0, $image->height / 2 - $mark->height / 2, $image->width, $image->height);
                        break;
                    case $this::POSITION_NORTH_WEST:
                        $mark = $mark->embed(0, 0, $image->width, $image->height);
                        break;
                    case $this::POSITION_CENTER:
                        $mark = $mark->embed($image->width / 2 - $mark->width / 2, $image->height / 2 - $mark->height / 2, $image->width, $image->height);
                        break;
                }

                $image = $image->composite($mark, [BlendMode::OVER]);
            }
        }

        $params = [];

        if ($this->quality)
            $params['Q'] = $this->quality;

        header('Content-type: image/jpeg');
        echo $image->jpegsave_buffer($params);
    }

    protected function get_file($path)
    {
        return $this->storage == 's3'
            ? $this->get_s3($path)
            : $this->get_local($path);
    }

    protected function get_local($path)
    {
        return file_get_contents(__DIR__ . '/' . $path);
    }

    protected function get_s3($path)
    {
        $object = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => ltrim($path, '/')
        ]);

        return $object['Body'];
    }
}