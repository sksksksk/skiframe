<?php
namespace Skar\Skiframe;

class Helper {
    const COMMENT_NO_PARSING = 'NO_SKIFRAME';
    const TYPE_GMAP = 10;
    const TYPE_YT = 20;
    const TYPE_VIMEO = 30;
    const TYPE_OTHERIFRAME = 100;

    const TYPE_SCRIPT = 200;

    const DEFAULT_WIDTH = 600;
    const DEFAULT_HEIGHT = 400;
    const MATCH_ERROR = 1000;

    public static function replaceIframes(?string $html, ?array $extensionSettings) : ?string {

        if (strpos($html, self::COMMENT_NO_PARSING) !== FALSE) {
            return $html;
        }

        if (!$extensionSettings) {
            return 'Skiframe extension got an empty configuration array';
        }

        // check if the markup contains an iframe tag.
        // in this case, replace it

        $replacementCount = 0;
        $iframeCount = 0;

        // check for empty field
        if (!trim($html)) {
            return $html;
        }
        $dom = new \DOMDocument;
        $dom->strictErrorChecking = TRUE;

        // disable php handling for warnings and errors from libxml. These will be handled internally by libxml
        $previousValue = libxml_use_internal_errors(true);

        // loadHTML converts all tags to lowercase. So I do not care about case
        libxml_clear_errors();
        // need to add html opening and closing tags as otherwise consecutive iframes are output nested
        $parseResult = $dom->loadHTML('<html><body>'.$html.'</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $lastError = libxml_get_last_error();
        //debug('WHJOLE DOC 1 '.$dom->saveHTML());

        if (!$parseResult || $lastError) {
            return static::getErrorMarkup('Unable to parse HTML. Add a HTML comment <!-- ' . self::COMMENT_NO_PARSING . ' --> to prevent processing with skiframe extension.'.($lastError?' Error: '.$lastError->message:'') );
        }
        libxml_use_internal_errors($previousValue);

        // if replaceifscripttag constant is true, and there is a script tag somewhere in the markup, then replace the whole content
        $scriptTagDetectedAndShouldReplace = false;
        if ( ($extensionSettings['replaceifscripttag']??false )) {
            if (count($dom->getElementsByTagName('script')) ) {
                $scriptTagDetectedAndShouldReplace = true;
            }
        }
        if (!$scriptTagDetectedAndShouldReplace) {
            $iframeList = [];
            // get list of iframes separately, as replacing them on the fly messes up the dom and iframes are lost
            foreach ($dom->getElementsByTagName('iframe') as $iframe) {
                $iframeList[] = $iframe;
            }

            foreach ($iframeList as $iframe) {
                $iframeCount++;
                $iframeSrc = $iframe->getAttribute('src');

                $sourceType = static::getIframeSourceType($iframeSrc, $extensionSettings);
                //debug("count $iframeCount / ".count($iframeList).", type:".$sourceType." for $iframeSrc");
                if ($sourceType == self::MATCH_ERROR) { // apparently php constants have no type, and thus === does not work
                    return static::getErrorMarkup(
                        'Unable to determine iframe src for ' . (htmlspecialchars(
                            $iframeSrc
                        )) . '. Add a HTML comment <!-- ' . self::COMMENT_NO_PARSING . ' --> to prevent processing with skiframe extension.'
                    );
                }


                $doReplace = false;
                if ($sourceType == self::TYPE_YT && ($extensionSettings['processyoutube'] ?? false)) {
                    $replacementCount++;
                    $doReplace = true;
                } else {
                    if ($sourceType == self::TYPE_GMAP && ($extensionSettings['processgmap'] ?? false)) {
                        $replacementCount++;
                        $doReplace = true;
                    } else {
                        if ($sourceType == self::TYPE_VIMEO && ($extensionSettings['processvimeo'] ?? false)) {
                            $replacementCount++;
                            $doReplace = true;
                        } else {
                            if (($extensionSettings['processotheriframe'] ?? false)) {
                                $replacementCount++;
                                $doReplace = true;
                            }
                        }
                    }
                }
                if ($doReplace) {
                    $width = intval($iframe->getAttribute('width'));
                    $height = intval($iframe->getAttribute('height'));
                    if (!$width) {
                        $width = DEFAULT_WIDTH;
                    }
                    if (!$height) {
                        $height = DEFAULT_HEIGHT;
                    }

                    $replacementNode = static::getReplacement(
                        $dom,
                        $iframe,
                        $sourceType,
                        $width,
                        $height,
                        $extensionSettings
                    );
                    if (!$replacementNode) {
                        return static::getErrorMarkup(
                            'Unable to generate replacement content for HTML with script tag' . (htmlspecialchars(
                                $iframeSrc
                            )) . '. Add a HTML comment <!-- ' . self::COMMENT_NO_PARSING . ' --> to prevent processing with skiframe extension.'
                        );
                    }
                    $iframe->parentNode->replaceChild($replacementNode, $iframe);
                }
            }

            if ($replacementCount) {
                // find the body tag that was inserted at the beginning and return only it's content
                return static::innerHTML($dom->getElementsByTagName('body')[0] ?? null);
            }
        }

        if ($scriptTagDetectedAndShouldReplace) {
            // We have to replace the whole content as there is a script tag present and replaceifscripttag constant is set to true
            // We do not have specific width and height in this case, so use some predefined values
            // As we do not have a specific node to replace, create a new DOMDocument, add a skmyspan to it, add the whole original content to the skmyspan, and replace the skmyspan
            // We do not need to duplicate the whole error handling, as it was already done at the beginning (I hope!)

            $newDom = new \DOMDocument;
            $newDom->loadHTML('<html><body><skmyspan>'.$html.'</skmyspan></body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $skmyspanList = [];
            // get list of iframes separately, as replacing them on the fly messes up the dom and iframes are lost
            foreach ($newDom->getElementsByTagName('skmyspan') as $skmyspan) {
                $skmyspanList[] = $skmyspan;
            }

            // there will be only a single skmyspan tag
            foreach ($skmyspanList as $skmyspan) {
                $replacementNode = static::getReplacement(
                    $newDom,
                    $skmyspan,
                    $sourceType,
                    self::DEFAULT_WIDTH,
                    self::DEFAULT_HEIGHT,
                    $extensionSettings
                );
                if (!$replacementNode) {
                    return static::getErrorMarkup(
                        'Unable to generate replacement content for HTML with script tag. Add a HTML comment <!-- ' . self::COMMENT_NO_PARSING . ' --> to prevent processing with skiframe extension.'
                    );
                }
                $skmyspan->parentNode->replaceChild($replacementNode, $skmyspan);
                return static::innerHTML($newDom->getElementsByTagName('body')[0] ?? null);
            }

        }

        return $html;
    }
    private static function getReplacement(\DOMDocument $dom, \DOMNode $node, $type, int $width, int $height, array $extensionSettings) : ?\DOMNode {
        $originalMarkup = $node->ownerDocument->saveHTML($node);
        $newNode = $dom->createElement('div');
        $fullWidth = $extensionSettings['fullwidth']??false;
        $nodeContainingContent = null;
        if ($fullWidth) {
            $newNode->setAttribute('class','tx_skiframe_container fullwidth');
            // https://css-tricks.com/aspect-ratio-boxes/
            $newNode->setAttribute('style','--aspect-ratio:'.$width.'/'.$height.';');

            // the content must be another node
            $nodeContainingContent = $dom->createElement('div');
            $nodeContainingContent->setAttribute('class','tx_skiframe_content');
            $newNode->appendChild($nodeContainingContent);
        }
        else {
            $newNode->setAttribute('class','tx_skiframe_container tx_skiframe_content');
            $newNode->setAttribute('style','width:'.$width.'px;height:'.$height.'px;');
            $nodeContainingContent = $newNode;
        }

        $newNode->setAttribute('data-original',$originalMarkup);
        //$nodeContainingContent->nodeValue = ' iframe in CE xxxxxxxxxxxxDOES match'.$originalMarkup;
        $messageMarkup = static::getMessageMarkup($type, $extensionSettings);
        if (!$messageMarkup) {
            return NULL;
        }
        static::appendHTML($nodeContainingContent, '<div class="messageContainer">'.$messageMarkup.'</div>');
        static::appendHTML($nodeContainingContent, '<div class="showButtonContainer"><span class="showButton">I understand. Show content</span></div>');
        return $newNode;
    }
    private static function getMessageMarkup($type, $extensionSettings) {
        $markup = null;
        switch ($type) {
            case self::TYPE_YT:
                $markup = $extensionSettings['youtubemessage']??null;
                break;
            case self::TYPE_GMAP:
                $markup = $extensionSettings['gmapmessage']??null;
                break;
            case self::TYPE_VIMEO:
                $markup = $extensionSettings['vimeomessage']??null;
                break;
        }
        if (!$markup) {
            $markup = $extensionSettings['otheriframemessage']??null;
        }
        if (!$markup) {
            return NULL;
        }

        return $markup;
    }
    private static function appendHTML(\DOMNode $parent, String $markup) {
        $tmpDoc = new \DOMDocument();
        $tmpDoc->loadHTML('<html><body>'.$markup.'</body></html>');
        foreach ($tmpDoc->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $node = $parent->ownerDocument->importNode($node, true);
            $parent->appendChild($node);
        }
    }
    private static function innerHTML(\DOMElement $element)
    {
        $doc = $element->ownerDocument;

        $html = '';

        foreach ($element->childNodes as $node) {
            $html .= $doc->saveHTML($node);
        }

        return $html;
    }
    private static function getErrorMarkup(string $msg) : string {
        return '<div class="tx_skiframe error">'.htmlspecialchars($msg).'</div>';
    }

    private static function getIframeSourceType(?string $src, array $extensionSettings) : ?string {
        if (!$src) {
            return self::MATCH_ERROR;
        }
        try {
            $match = preg_match($extensionSettings['gmapregex']??'//', $src);
            if ($match === FALSE) {
                return self::MATCH_ERROR;
            }
            if ($match) {
                return self::TYPE_GMAP;
            }

            $match = preg_match($extensionSettings['youtuberegex']??'//', $src);
            if ($match === FALSE) {
                return self::MATCH_ERROR;
            }
            if ($match) {
                return self::TYPE_YT;
            }

            $match = preg_match($extensionSettings['vimeoregex']??'//', $src);
            if ($match === FALSE) {
                return self::MATCH_ERROR;
            }
            if ($match) {
                return self::TYPE_VIMEO;
            }
        } catch (\Exception $e) {
            //debug($e->getMessage());
            return self::MATCH_ERROR;
        }
        return self::TYPE_OTHERIFRAME;
    }
}