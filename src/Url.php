<?php declare(strict_types=1);

namespace levmorozov\phpresizer;

class Url
{
    public static function encodeParams(array $options = []) : string
    {
        $parts = [];

        foreach ($options as $name => $o) {
            if (\is_int($name) && \is_string($o)) {
                $parts[] = $o;
                continue;
            }

            $part = '';

            switch ($name) {
                case 'resize':
                    $part = 'r';
                    if (isset($o['fit_mode'])) {
                        if ($o['fit_mode'] === 'fill') {
                            $part = 'rf';
                        } elseif ($o['fit_mode'] === 'crop') {
                            $part = 'rc';
                        }
                    }
                    if (isset($o['width']) and $o['width']) {
                        $part .= $o['width'];
                    }
                    $part .= 'x';
                    if (isset($o['height']) and $o['height']) {
                        $part .= $o['height'];
                    }
                    break;

                case 'quality':
                    $part = "q$o";
                    break;

                case 'pixel_ratio':
                    $part = "p$o";
                    break;

                case 'crop':
                    $part = "c{$o['x']}x{$o['y']}x{$o['width']}x{$o['height']}";
                    break;

                case 'gravity':
                    $part = 'g';
                    if ($o['mode'] == 'focal point') {
                        $part .= 'f' . $o['x'] . 'x' . $o['y'];
                    } elseif ($o['mode'] === 'smart') {
                        $part .= 's';
                    }
                    break;

                case 'background':
                    $part = "b$o";
                    break;

                case 'watermarks':
                    foreach ($o as $w) {
                        $part = 'w';
                        if (isset($w['position'])) {
                            switch ($w['position']) {
                                case 'south east':
                                    $part .= Resizer::POSITION_SOUTH_EAST;
                                    break;
                                case 'south west':
                                    $part .= Resizer::POSITION_SOUTH_WEST;
                                    break;
                                case 'north_west':
                                    $part .= Resizer::POSITION_NORTH_WEST;
                                    break;
                                case 'center':
                                    $part .= Resizer::POSITION_CENTER;
                                    break;
                                default:
                                    $part .= $w['position'];
                            }
                        }
                        $part .= '-';
                        if (isset($w['size']) && $w['size']) {
                            $part .= $w['size'];
                        }
                        $parts[] = $part . '-' . \urlencode($w['path']);
                        $part = '';
                    }
                    break;

                case 'filters':
                    foreach ($o as $f) {
                        // TODO: Switch case for filters
                        $parts[] = 'f' . \substr($f, 0, 1);
                        $part = '';
                    }
                    break;
                case 'presets':
                    foreach ($o as $preset) {
                        $parts[] = "_$preset";
                        $part = '';
                    }
            }

            if ($part) {
                $parts[] = $part;
            }
        }

        if (empty($parts)) {
            return 'n' ;
        }

        return \implode(',', $parts);
    }

    /**
     * Return short base64 encoded hash of $string + $secret
     *
     * @param string $string
     * @param string $secret
     * @param int    $offset
     * @param int    $size
     * @return string
     */
    public static function shortHash(string $string, string $secret, int $offset = 8, int $size = 3) : string
    {
        \assert($offset < 16 && $size < 16);
        return \rtrim(\strtr(\base64_encode(\substr(\md5($string.$secret, true), $offset, $size)), '+/', '-_'), '=');
    }


    /**
     * Parses URI like "sign/url", checks sign and returns url if everything ok
     * othewise null
     *
     * @param $secret
     * @return string|null
     */
    public static function parseUri(string $secret) : ?string
    {
        $uri = \ltrim($_SERVER['REQUEST_URI'], '/');
        [$sign, $url] = \explode('/', $uri, 2);

        if($sign !== self::shortHash($url, $secret)) {
            \http_response_code(404);
            return null;
        }

        return $url;
    }
}
