<?php

namespace mikemeier\SonataMedia\Resizer;

use Gaufrette\File;
use Imagine\Image\ImageInterface;
use Sonata\MediaBundle\Metadata\MetadataBuilderInterface;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Resizer\ResizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Imagine\Image\Box;
use Imagine\Exception\InvalidArgumentException;

class ImagickCropThumbnailResizer implements ResizerInterface
{
    /**
     * @var MetadataBuilderInterface
     */
    protected $metadata;

    /**
     * @var string
     */
    protected $mode;

    /**
     * @param MetadataBuilderInterface $metadata
     * @param string $mode
     * @throws InvalidArgumentException
     * @throws \LogicException
     */
    public function __construct(MetadataBuilderInterface $metadata, $mode = ImageInterface::THUMBNAIL_INSET)
    {
        $this->metadata = $metadata;
        $this->mode = $mode;

        $allowedModes = array(ImageInterface::THUMBNAIL_INSET, ImageInterface::THUMBNAIL_OUTBOUND);
        if(!in_array($mode, $allowedModes)){
            throw new InvalidArgumentException(sprintf('Invalid mode specified, allowed are: %s', json_encode($allowedModes)));
        }
    }

    /**
     * @param MediaInterface $media
     * @param File $in
     * @param File $out
     * @param string $format
     * @param array $settings
     * @throws \RuntimeException
     * @return void
     */
    public function resize(MediaInterface $media, File $in, File $out, $format, array $settings)
    {
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('SonataImagickCropThumbnailResizer requires the PHP imagick extension to be installed');
            return;
        }

        /* Allow "nofill" option to be passed using quality
         * Quite hacky, but configuration does not allow for extra options.
         * Would be better fixed with multiple resizers:
         *   https://github.com/sonata-project/SonataMediaBundle/pull/693
         */
        if ($qualityDashPos = strpos($settings['quality'], '-')) {
            $quality = substr($settings['quality'], 0, $qualityDashPos - 1);
            $fill = strpos($settings['quality'], 'nofill') === false;
        } else {
            $quality = $settings['quality'];
            $fill = true;
        }

        /* Example A and B: 200, 160 */
        list($desiredWidth, $desiredHeight) = $this->getDimensions($media, $settings);

        $image = new \Imagick();
        $image->readImageBlob($in->getContent());

        if ($fill) {
            $this->cropToMatchRatio($image, $desiredWidth, $desiredHeight);
            /* Resize to fill desired dimensions */
            $image->resizeImage($desiredWidth, $desiredHeight, \imagick::FILTER_CATROM, 1);
        } else {
            list($scaleFactorX, $scaleFactorY) = $this->getScaleFactors($image, $desiredWidth, $desiredHeight);
            /* Only scale down, don't scale up */
            if ($scaleFactorX < 1 || $scaleFactorY < 1) {
                /* Make sure we hit the bounding dimension exactly */
                if ($scaleFactorX < $scaleFactorY) {
                    $resizeWidth = $desiredWidth;
                    $resizeHeight = round($image->getImageHeight() * $scaleFactorX);
                } elseif ($scaleFactorY < $scaleFactorX) {
                    $resizeWidth = round($image->getImageWidth() * $scaleFactorY);
                    $resizeHeight = $desiredHeight;
                } else {
                    $resizeHeight = $desiredHeight;
                    $resizeWidth = $desiredWidth;
                }

                $image->resizeImage($resizeWidth, $resizeHeight, \Imagick::FILTER_CATROM, 1);
            }
        }

        /* Save as JPEG */
        $image->setCompression($image::COMPRESSION_JPEG);
        $image->setCompressionQuality(isset($quality) ? $quality : 90);

        $out->setContent($image, $this->metadata->get($media, $out->getName()));
    }

    private function getScaleFactors(\Imagick $image, $desiredWidth, $desiredHeight)
    {
        /* Example A: 600, 400
         * Example B: 100, 200
         */
        $originalWidth = $image->getImageWidth();
        $originalHeight = $image->getImageHeight();

        /* Example A: 0.333 , 0.4
         * Example B: 2, 0.8
         */
        return [$desiredWidth / $originalWidth, $desiredHeight / $originalHeight];
    }

    /**
     * Match the ratio of the desired dimensions
     */
    private function cropToMatchRatio(\Imagick $image, $desiredWidth, $desiredHeight)
    {
        list($scaleFactorX, $scaleFactorY) = $this->getScaleFactors($image, $desiredWidth, $desiredHeight);

        /* Shave of sides of the image to make it match the desired ratio */
        if ($scaleFactorX < $scaleFactorY) {
            /* Example A: intermediateWidth = 499,5 */
            $intermediateWidth = ($scaleFactorX * $image->getImageWidth()) / $scaleFactorY;
            /* Example A: Shave floor(50.25) = 50 pixels horizontally of each side */
            $image->shaveImage(floor(($image->getImageWidth() - $intermediateWidth) / 2), 0);
        } elseif ($scaleFactorX > $scaleFactorY) {
            /* Example B: intermediateHeight = (0.8 * 200) / 2 = 80 */
            $intermediateHeight = ($scaleFactorY * $image->getImageHeight()) / $scaleFactorX;
            /* Example B: Shave floor(60) = 60 pixels veritcally of each side */
            $image->shaveImage(0, floor(($image->getImageHeight() - $intermediateHeight) / 2));
        }
    }

    /**
     * @param MediaInterface $media
     * @param array $settings
     * @return Box
     */
    public function getBox(MediaInterface $media, array $settings)
    {
        list($width, $height) = $this->getDimensions($media, $settings);
        return new Box($width, $height);
    }

    /**
     * @param MediaInterface $media
     * @param array $settings
     * @return array
     * @throws \RuntimeException
     */
    protected function getDimensions(MediaInterface $media, array $settings)
    {
        if (!$settings['width'] && !$settings['height']) {
            throw new \RuntimeException(sprintf('Width and height parameter is missing in context "%s" for provider "%s"', $media->getContext(), $media->getProviderName()));
        }

        if ($settings['width'] && $settings['height']) {
            $width  = $settings['width'];
            $height = $settings['height'];
        } elseif ($settings['width']) {
            $width = $settings['width'];
            if ($media->getWidth() > $media->getHeight()) {
                $height = $width / ($media->getWidth() / $media->getHeight());
            } else {
                $height = $width * ($media->getHeight() / $media->getWidth());
            }
        } else {
            $height = $settings['height'];
            if ($media->getWidth() > $media->getHeight()) {
                $width = $height * ($media->getWidth() / $media->getHeight());
            }else {
                $width = $height / ($media->getHeight() / $media->getWidth());
            }
        }

        return array($width, $height);
    }
}
