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
        if(!extension_loaded('imagick')){
            $out->setContent($in->getContent());
            return;
        }

        list($width, $height) = $this->getDimensions($media, $settings);

        $image = new \Imagick();

        //$image->setimagecompression($image::COMPRESSION_JPEG);
        //$image->setcompressionquality(isset($settings['quality']) ? $settings['quality'] : 90);

        $image->setCompression($image::COMPRESSION_LOSSLESSJPEG);
        $image->setCompressionQuality(isset($settings['quality']) ? $settings['quality'] : 90);

        $image->readimageblob($in->getContent());
        $image->cropthumbnailimage($width, $height);

        $out->setContent($image, $this->metadata->get($media, $out->getName()));
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

        if($settings['width'] && $settings['height']){
            $width  = $settings['width'];
            $height = $settings['height'];
        }elseif($settings['width']){
            $width = $settings['width'];
            if($media->getWidth() > $media->getHeight()){
                $height = $width / ($media->getWidth() / $media->getHeight());
            }else{
                $height = $width * ($media->getHeight() / $media->getWidth());
            }
        }else{
            $height = $settings['height'];
            if($media->getWidth() > $media->getHeight()){
                $width = $height * ($media->getWidth() / $media->getHeight());
            }else{
                $width = $height / ($media->getHeight() / $media->getWidth());
            }
        }

        return array($width, $height);
    }
}