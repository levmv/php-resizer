# php-resizer
Microservice to resize images



## Generating the URL

The URL should contain the path and options, like this:
`/{options}/{encoded path}`

Example:

domain.com/photo/w100,h200,c/encodedimagepath.jpg

## Full list of options: 
```
  w: width
  h: height 
  c: crop
  q: quality
  g: gravity   
  b: background 
  f: filter
  r: rotate
  s: scale 
```

### `w` : width, `h` : height
Width and height parameters define the size of the resulting image. By default works like defining a rectangle that will define a max-width and max-height and the image will scale propotionally to fit that area without cropping.
 
### `c` : crop 
`c{optional parameters}`
When used withoud parameters and both width and height are set, this allows the image to be cropped so it fills the **width x height** area.  

Parameters:

`XxY` – left top point from where crop starting. 

`XxYxWIDTHxHEIGHT` - starting point and size of cropping area.

Example:

domain.com/photo/w100,h200,c/path – Fit image to 100x200 and crop everything else
domain.com/photo/w100,h200,c10x5/path – Crop image by rectangle starting from 10x5 and 100x200 size.
domain.com/photo/w100,h200,c10x5x200x400/path – Crop image by rectangle starting from 10x5 and 200x400 size. After that resize it to 100x200

  
### `g` : Gravity

When crop is applied, changing the gravity will define which part of the image is kept inside the crop area. The basic options are:

* ~`no` — north (top edge);~
* ~`so` — south (bottom edge);~
* ~`ea` — east (right edge);~
* ~`we` — west (left edge);~
* `ce` — center (default mode);
* `sm` — smart. `libvips` detects the most "interesting" section of the image and considers it as the center of the resulting image.  
