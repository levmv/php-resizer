<?php

namespace levmorozov\phpresizer;

class UrlDecoder
{
    protected string $uri;

    public function __construct(string $uri = null)
    {
        $this->uri = ($uri === null)
            ? \ltrim($_SERVER['REQUEST_URI'], '/')
            : $uri;
    }

    public function decode(): array
    {
        $parts = \explode('/', $this->uri, 2);

        $opts = [];

        $opts['path'] = \ltrim(\urldecode($parts[1]), '/');

        foreach (\explode(',', $parts[0]) as $option) {

            $name = \substr($option, 0, 1);
            $value = \substr($option, 1);

            if (!$value) {
                throw new \Exception('Wrong settings. Empty value');
            }

            switch ($name) {
                case 'r':
                    if ($value[0] == 'f') {
                        $opts['mode'] = Resizer::MODE_FILL;
                        $value = \substr($value, 1);
                    } elseif ($value[0] == 'c') {
                        $opts['mode'] = Resizer::MODE_CROP;
                        $value = \substr($value, 1);
                    }
                    $sizes = explode('x', $value);
                    if (\count($sizes) == 2) {
                        $opts['resize'] = true;
                        $opts['width'] = (int)$sizes[0];
                        $opts['height'] = (int)$sizes[1];
                    } elseif (\count($sizes) == 1 AND $sizes[0]) {
                        $opts['resize'] = true;
                        $opts['width'] = (int)$sizes[0];
                    }
                    break;
                case 'c':
                    $numbers = \explode('x', $value);
                    if (\count($numbers) == 4) {
                        $opts['crop'] = true;
                        $opts['crop_x'] = (int)$numbers[0];
                        $opts['crop_y'] = (int)$numbers[1];
                        $opts['crop_width'] = (int)$numbers[2];
                        $opts['crop_height'] = (int)$numbers[3];
                    }
                    break;
                case 'q':
                    $opts['quality'] = (int)$value;
                    break;
                case 'g':
                    if ($value[0] == 'f') {
                        $opts['gravity'] = Resizer::GRAVITY_FOCAL;
                        $point = explode('x', substr($value, 1));
                        if (count($point) == 2) {
                            $opts['gravity_x'] = (int)$point[0];
                            $opts['gravity_y'] = (int)$point[1];
                        }
                    } elseif ($value[0] == 's') {
                        $opts['gravity'] = Resizer::GRAVITY_SMART;
                    }
                    break;
                case 'b':
                    $opts['background'] = \sscanf($value, "%02x%02x%02x");
                    break;
                case 'w':
                    $opts = \explode('-', $value);
                    if (\count($opts) == 3 AND $opts[2]) {
                        if (!isset($opts['watermark']))
                            $opts['watermark'] = [];
                        $opts['watermark'][] = [
                            'path' => \urldecode($opts[2]),
                            'position' => $opts[0] ? $opts[0] : Resizer::POSITION_SOUTH_EAST,
                            'size' => $opts[1] ? (int)$opts[1] : 100
                        ];
                    }
                    break;
                case 'f':
                    if (!isset($opts['filters']))
                        $opts['filters'] = [];
                    $opts['filters'][] = $value;
                    break;
                case 'p':
                    switch ($value) {
                        case '2':
                            $opts['pixel_ratio'] = Resizer::DPR_TWO;
                            break;
                        case '1.5':
                            $opts['pixel_ratio'] = Resizer::DPR_1_5;
                            break;
                        case '3':
                            $opts['pixel_ratio'] = Resizer::DPR_THREE;
                            break;
                    }
                    break;
                case 't';
                    $opts['tag'] = (int)$value;
                    break;
                case 'n':
                    break;
                default:
                    throw new \Exception("Unsupported param $name");
            }
        }

        return $opts;
    }
}