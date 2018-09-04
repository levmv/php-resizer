<?php

namespace levmorozov\phpresizer;

class Url
{
    public function make($path, $options)
    {
        $parts = [];

        // TODO: Empty options
        foreach ($options as $name => $o) {

            $part = '';

            switch ($name) {

                case 'resize':
                    $part = 'r';
                    if ($o['fit_mode']) {
                        if ($o['fit_mode'] == 'fill')
                            $part .= 'f';
                        elseif ($o['fit_mode'] == 'crop')
                            $part .= 'c';
                    }
                    if ($o['width'])
                        $part .= $o['width'];
                    $part .= 'x';
                    if ($o['height'])
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

                case 'quality':
                case 'background':
                case 'pixel_density_ratio':
                    $part = substr($name, 0, 1) . $o;
                    break;

                case 'watermarks':
                    foreach ($o as $w) {
                        $part = 'w';
                        if ($w['position']) {
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
                        if ($w['size'])
                            $part .= $w['size'];
                        $parts[] = $part . '-' . $w['path'];
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
            }

            if ($part)
                $parts[] = $part;
        }

        return implode(',', $parts) . '/' . $path;
    }
}