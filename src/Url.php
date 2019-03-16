<?php

namespace levmorozov\phpresizer;

class Url
{
    public function make($path, $options) {
        $parts = [];

        $options = array_replace([
            'resize' => null,
            'crop' => null,
            'gravity' => null,
            'watermarks' => null,
            'quality' => null,
            'quality_webp' => null,
            'background' => null,
            'pixel_ratio' => null,
            'filters' => null,
            'auto_webp' => null,
            'tag' => null
        ], $options);

        // TODO: Empty options
        foreach ($options as $name => $o) {

            if (!$o)
                continue;

            $part = '';

            switch ($name) {

                case 'resize':
                    $part = 'r';
                    if (isset($o['fit_mode']) AND $o['fit_mode']) {
                        if ($o['fit_mode'] == 'fill')
                            $part .= 'f';
                        elseif ($o['fit_mode'] == 'crop')
                            $part .= 'c';
                    }
                    if (isset($o['width']) AND $o['width'])
                        $part .= $o['width'];
                    $part .= 'x';
                    if (isset($o['height']) AND $o['height'])
                        $part .= $o['height'];
                    break;

                case 'gravity':
                    $part = 'g';
                    if ($o['mode'] == 'focal point')
                        $part .= 'f' . $o['x'] . 'x' . $o['y'];
                    elseif ($o['mode'] == 'smart')
                        $part .= 's';
                    break;

                case 'crop':
                    $part = 'c' . $o['x'] . 'x' . $o['y'] . 'x' . $o['width'] . 'x' . $o['height'];
                    break;

                case 'quality_webp':
                    $part = 'qw' . $o;
                    break;

                case 'quality':
                case 'background':
                case 'pixel_ratio':
                    $part = substr($name, 0, 1) . $o;
                    break;

                case 'watermarks':
                    foreach ($o as $w) {
                        $part = 'w';
                        if (isset($w['position']) AND $w['position']) {
                            switch ($w['position']) {
                                case 'north':
                                    $part = Resizer::POSITION_NORTH;
                                    break;
                                case 'north east':
                                    $part = Resizer::POSITION_NORTH_EAST;
                                    break;
                                case 'east':
                                    $part = Resizer::POSITION_EAST;
                                    break;
                                case 'south east':
                                    break;
                                case 'south':
                                    $part = Resizer::POSITION_SOUTH;
                                    break;
                                case 'south west':
                                    $part = Resizer::POSITION_SOUTH_WEST;
                                    break;
                                case 'west':
                                    $part = Resizer::POSITION_WEST;
                                    break;
                                case 'north_west':
                                    $part = Resizer::POSITION_NORTH_WEST;
                                    break;
                                case 'center':
                                    $part = Resizer::POSITION_CENTER;
                                    break;
                            }
                        }
                        $part .= '-';
                        if (isset($w['size']) AND $w['size'])
                            $part .= $w['size'];
                        $parts[] = $part . '-' . urlencode($w['path']);
                        $part = '';
                    }
                    break;

                case 'filters':
                    foreach ($o as $f) {
                        // TODO: Switch case for filters
                        $parts[] = 'f' . substr($f, 0, 1);
                        $part = '';
                    }
                    break;
                case 'tag':
                    $path = 't' . $o;

                case 'auto_webp':
                    $part = 'aw';
                    break;
            }

            if ($part)
                $parts[] = $part;
        }

        return implode(',', $parts) . '/' . urlencode($path);
    }
}