<?php

/**
 * League.Csv (https://csv.thephpleague.com).
 *
 * @author  Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @license https://github.com/thephpleague/csv/blob/master/LICENSE (MIT License)
 * @version 9.2.0
 * @link    https://github.com/thephpleague/csv
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Csv\Polyfill;

use Generator;
use League\Csv\Stream;
use SplFileObject;
use TypeError;
use function explode;
use function get_class;
use function gettype;
use function in_array;
use function is_object;
use function ltrim;
use function rtrim;
use function sprintf;
use function str_replace;
use function substr;

/**
 * A Polyfill to PHP's SplFileObject behavior when reading a CSV document
 * with the SplFileObject::READ_CSV and SplFileObject::SKIP_EMPTY flags on
 * and the empty string as the escape parameter.
 *
 * <code>
 * $file = new SplFileObject('/path/to/file.csv', 'r');
 * $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
 * $file->setCsvControl($delimiter, $enclosure, ''); //this does not currently in any PHP stable release
 * </code>
 *
 * instead you can do this
 *
 * <code>
 * $file = new SplFileObject('/path/to/file.csv', 'r');
 * $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
 * $file->setCsvControl($delimiter, $enclosure, $escape);
 * EmptyEscapeParser::parse($file); //parsing will be done while ignoring the escape character value.
 * </code>
 *
 * @see https://php.net/manual/en/function.fgetcsv.php
 * @see https://php.net/manual/en/function.fgets.php
 * @see https://tools.ietf.org/html/rfc4180
 * @see http://edoceo.com/utilitas/csv-file-format
 *
 * @internal used internally to parse a CSV document without using the escape character
 */
final class EmptyEscapeParser
{
    /**
     * @internal
     */
    const FIELD_BREAKS = [false, '', "\r\n", "\n", "\r"];

    /**
     * @var SplFileObject|Stream
     */
    private static $document;

    /**
     * @var string
     */
    private static $delimiter;

    /**
     * @var string
     */
    private static $enclosure;

    /**
     * @var string
     */
    private static $trim_mask;

    /**
     * @var string|bool
     */
    private static $line;

    /**
     * Converts the document into a CSV record iterator.
     *
     * Each record array contains strings elements.
     *
     * @param SplFileObject|Stream $document
     *
     * @return Generator|array[]
     */
    public static function parse($document): Generator
    {
        self::$document = self::filterDocument($document);
        list(self::$delimiter, self::$enclosure, ) = self::$document->getCsvControl();
        self::$trim_mask = str_replace([self::$delimiter, self::$enclosure], '', " \t\0\x0B");
        self::$document->setFlags(0);
        self::$document->rewind();
        while (self::$document->valid()) {
            $record = self::extractRecord();
            if ([null] !== $record) {
                yield $record;
            }
        }
    }

    /**
     * Filters the submitted document.
     *
     * @param SplFileObject|Stream $document
     *
     * @return SplFileObject|Stream
     */
    private static function filterDocument($document)
    {
        if ($document instanceof Stream || $document instanceof SplFileObject) {
            return $document;
        }

        throw new TypeError(sprintf(
            '%s::parse expects parameter 1 to be a %s or a SplFileObject object, %s given',
            static::class,
            Stream::class,
            is_object($document) ? get_class($document) : gettype($document)
        ));
    }

    /**
     * Extracts a record form the CSV document.
     */
    private static function extractRecord(): array
    {
        $record = [];
        self::$line = self::$document->fgets();
        do {
            $method = 'extractFieldContent';
            $buffer = ltrim(self::$line, self::$trim_mask);
            if (($buffer[0] ?? '') === self::$enclosure) {
                $method = 'extractEnclosedFieldContent';
                self::$line = $buffer;
            }

            $record[] = self::$method();
        } while (false !== self::$line);

        return $record;
    }

    /**
     * Extracts the content from a field without enclosure.
     *
     * - Leading and trailing whitespaces must be removed.
     * - trailing line-breaks must be removed.
     *
     * @return null|string
     */
    private static function extractFieldContent()
    {
        if (in_array(self::$line, self::FIELD_BREAKS, true)) {
            self::$line = false;

            return null;
        }

        list($content, self::$line) = explode(self::$delimiter, self::$line, 2) + [1 => false];
        if (false === self::$line) {
            return rtrim($content, "\r\n");
        }

        return $content;
    }

    /**
     * Extracts the content from a field with enclosure.
     *
     * - Field content can spread on multiple document lines.
     * - Content inside enclosure must be preserved.
     * - Double enclosure sequence must be replaced by single enclosure character.
     * - Trailing line break must be removed if they are not part of the field content.
     * - Invalid field content are treated as per fgetcsv behavior.
     */
    private static function extractEnclosedFieldContent(): string
    {
        if ((self::$line[0] ?? '') === self::$enclosure) {
            self::$line = substr(self::$line, 1);
        }

        $content = '';
        while (false !== self::$line) {
            list($buffer, $remainder) = explode(self::$enclosure, self::$line, 2) + [1 => false];
            $content .= $buffer;
            if (false !== $remainder) {
                self::$line = $remainder;
                break;
            }
            self::$line = self::$document->fgets();
        }

        if (in_array(self::$line, self::FIELD_BREAKS, true)) {
            self::$line = false;

            return rtrim($content, "\r\n");
        }

        $char = self::$line[0] ?? '';
        if (self::$delimiter === $char) {
            self::$line = substr(self::$line, 1);

            return $content;
        }

        if (self::$enclosure === $char) {
            return $content.self::$enclosure.self::extractEnclosedFieldContent();
        }

        return $content.self::extractFieldContent();
    }
}
