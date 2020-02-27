# php-resizer
Microservice to resize images on the fly


## Generating the URL

The URL should contain the path and options, like this:
`/{options}/{encoded path}`

Example:

domain.com/photo/w100,h200,c/encodedimagepath.jpg

## Full list of options: 
```
R: resize 
C: crop
Q: quality
G: gravity   
B: background 
W: watermark
F: filter 
P: pixel density ratio
```

### `R` : resize
`R({fit mode}){size}`
 
Fit mode:
contain. Default. Resizes the image to fit within the width and height boundaries without cropping, distorting or altering the aspect ratio.

f - fill. Resizes the image to fit within the width and height boundaries without cropping or distorting the image, and the remaining space is filled with the background color.

c - crop. Resizes the image to fill the width and height boundaries and crops any excess image data. 
 
### `C` : crop 
`C{x}x{y}x{width}x{height}`
Creates an image whose sizes are exactly the ones specified. The resized image is obtained picking it from a rectangle of the same sizes from the center of the image.
  
### `G` : Gravity

When resize (in crop mode) is applied, changing the gravity will define which part of the image is kept inside the crop area. The basic options are: 
* `ce` — center (default mode);
* `sm` — smart. `libvips` detects the most "interesting" section of the image and considers it as the center of the resulting image.  
