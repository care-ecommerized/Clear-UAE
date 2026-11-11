{{--
  ============================================================
  _dark-mode.blade.php — Shared dark-mode / night-view guard
  ============================================================
  Mail clients (Apple Mail, Outlook, Gmail) recolor emails when the
  device is in dark mode, which broke our cream/teal design. Including
  this partial in an email's <head> keeps the SAME (light) design in
  night view by:
    1. Declaring color-scheme support so Apple Mail / Outlook stop
       blindly inverting our colours.
    2. Re-asserting the exact brand palette under
       @media (prefers-color-scheme: dark)  (Apple Mail, Outlook macOS).
    3. Re-asserting it again via Outlook.com's [data-ogsc]/[data-ogsb]
       hooks (new Outlook / Outlook.com).
  Note: Gmail's mobile apps ignore <style>/media queries and apply their
  own contrast adjustment, so a small shift can still occur there — this
  is the best-effort that every spec-respecting client honours.

  Assumes the standard order-email class names: .container .header
  .content .order-number .shipping-info .totals .footer + th/td.
  ============================================================
--}}
@php $brandColor = \App\Support\Brand::color(); @endphp
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }

    @media (prefers-color-scheme: dark) {
        /* Lock the surfaces + base text. We deliberately DON'T blanket-override
           every <td>: Apple Mail honours these declarations and won't invert, so
           inline accent colours (e.g. the green discount) stay as designed. */
        body            { background: #f5f0e8 !important; color: {{ $brandColor }} !important; }
        .container      { background: #ffffff !important; }
        .header         { background: {{ $brandColor }} !important; color: #ffffff !important; }
        .header h1,
        .header a       { color: #ffffff !important; }
        .order-number,
        th,
        .shipping-info  { background: #f5f0e8 !important; }
        th,
        .shipping-info h3 { color: #666666 !important; }
        .footer         { color: #999999 !important; }
        .order-number span,
        .totals .total  { color: {{ $brandColor }} !important; }
    }

    /* Outlook.com / new Outlook — it stamps elements with data-ogsc (text)
       and data-ogsb (background) when it forces dark mode. */
    [data-ogsb] body           { background: #f5f0e8 !important; }
    [data-ogsb] .container     { background: #ffffff !important; }
    [data-ogsb] .header        { background: {{ $brandColor }} !important; }
    [data-ogsb] .order-number,
    [data-ogsb] th,
    [data-ogsb] .shipping-info { background: #f5f0e8 !important; }
    [data-ogsc] .header h1,
    [data-ogsc] .header a      { color: #ffffff !important; }
    [data-ogsc] .content,
    [data-ogsc] .content h2,
    [data-ogsc] .content p,
    [data-ogsc] td,
    [data-ogsc] .order-number span,
    [data-ogsc] .totals .total { color: {{ $brandColor }} !important; }
    [data-ogsc] th,
    [data-ogsc] .shipping-info h3 { color: #666666 !important; }
    [data-ogsc] .footer        { color: #999999 !important; }
</style>
