This is a project for a wordpress plugin which converts img tags in old posts into picture tags.
The plugin works on the fly when displaying the site. It does not change the post content in the database.
The current image tag looks something like this example:
<img class="aligncenter size-full wp-image-64676" src="https://www.mamanvoyage.com/wp-content/uploads/2026/03/IMG_1987.jpeg" alt="desert des bardenas van" width="960" height="720" />

It is safe to assume that:
- for each original jpeg/jpg image there exists a corresponding webp image (.jpeg.webp or .jpg.webp)
- for each 960 wide image there exist 640 and 480 width images following the standard wordpress naming convention with name-640xheight.jpp.webp etc.

The Picture tag should start with a responsive srcset offering each full width (960) and 640 and 480 wide webp images. Then a fallback with the same jpg/jpeg image srcset.
The image srcset takes the other attributes from the original image tag (classes, alt etc.)

If the original img tag is wrapped in a centered p tag, the p tag can be completly dropped since the picture will be centered via its css class. I.e. this:
<p style="text-align: center;"><img class="aligncenter size-full wp-image-64676" src="https://www.mamanvoyage.com/wp-content/uploads/2026/03/IMG_1987.jpeg" alt="desert des bardenas van" width="960" height="720" /></p>
drop the opening and closing p tags completly.

If the original img tag is immediately followed by a short text inside en em tag - like this example:
<img class="aligncenter size-full wp-image-64676" src="https://www.mamanvoyage.com/wp-content/uploads/2026/03/IMG_1987.jpeg" alt="desert des bardenas van" width="960" height="720" /><em>Image caption</em>

Then wrap the picture tag in a figure tag and add the text inside the em tag as a figcaption below the picture tag - like this example:
	...
	</picture>
	<figcaption>Image caption</figcaption>
</figure>

If the original image is less than 960 wide (missing a width="960" or lower width) then do not convert the img tag into a picture tag - leave the img tag unchanged.

Be proactive and inventive

### Safety and permissions

Allowed without prompt:
- read files, list files, write files, get any file (html, css, js) from http://www.mamanvoyage.com/

Note: proactively suggest installation of tools which would help with your task for installation.
