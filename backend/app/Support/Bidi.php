<?php

namespace App\Support;

/**
 * Right-to-left handling for Arabic text printed in left-to-right documents
 * (the customer invoice PDF).
 *
 * Inside a left-to-right block, an Arabic line that contains an English word
 * or a number is split by the bidi algorithm into separate runs, and those
 * runs are laid out left to right. "حي الشبرا، شقة بجانب برج اتصالات stc، مبنى 12"
 * then reads, right to left, as "مبنى 12 ،stc … حي الشبرا" — the pieces are in
 * the wrong order.
 *
 * The normal fix is dir="rtl" on the element, but the PDF engine this was
 * written for (mPDF) ignored it inside table cells, which is where the
 * invoice's address block lives. The invoice now renders with TCPDF and keeps
 * this approach, which works the same there and does not depend on how the
 * engine treats dir="rtl". So the direction is put into the text itself instead: wrapping the line in RIGHT-TO-LEFT
 * EMBEDDING (U+202B) ... POP DIRECTIONAL FORMATTING (U+202C) makes the whole
 * line one right-to-left unit, whatever block it sits in. Both characters are
 * invisible.
 */
class Bidi
{
    private const RLE = "\u{202B}";

    private const PDF = "\u{202C}";

    /**
     * Wraps the text in a right-to-left embedding when its first letter is
     * Arabic (the same rule as HTML dir="auto"); other text is returned as is.
     */
    public static function rtlIfArabic(?string $text): string
    {
        $text = (string) $text;

        if (! preg_match('/^[^\p{L}]*\p{Arabic}/u', $text)) {
            return $text;
        }

        return self::RLE.$text.self::PDF;
    }
}
