<?php
declare(strict_types=1);

/**
 * Píxeles: Meta, TikTok, GA4 y Google Tag Manager. Los IDs se configuran en Admin → Píxeles y analítica.
 * Eventos estándar: [nombre Meta, datos, eventID opcional] → se traducen a GA4 y TikTok.
 */
function pixel_head(array $events): string
{
    $meta = preg_replace('/\D/', '', (string) setting('meta_pixel_id', ''));
    $ga4  = preg_replace('/[^A-Z0-9-]/i', '', (string) setting('ga4_id', ''));
    $gtm  = preg_replace('/[^A-Z0-9-]/i', '', (string) setting('gtm_id', ''));
    $tt   = preg_replace('/[^A-Z0-9]/i', '', (string) setting('tiktok_pixel_id', ''));
    $out = '';
    if ($v = (string) setting('meta_domain_verification', '')) { $out .= '<meta name="facebook-domain-verification" content="' . e($v) . "\">\n"; }
    if ($v = (string) setting('google_site_verification', '')) { $out .= '<meta name="google-site-verification" content="' . e($v) . "\">\n"; }
    if (!$meta && !$ga4 && !$gtm && !$tt) {
        return $out;
    }
    $js = "window.dataLayer=window.dataLayer||[];\n";
    if ($gtm) {
        $js .= "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$gtm}');\n";
    }
    if ($ga4) {
        $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$ga4}\"></script>\n";
        $js .= "function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$ga4}');\n";
    }
    if ($meta) {
        $js .= "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$meta}');fbq('track','PageView');\n";
    }
    if ($tt) {
        $js .= "!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.load=function(e){var n='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._u=n;var o=d.createElement('script');o.async=!0;o.src=n+'?sdkid='+e+'&lib='+t;var a=d.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load('{$tt}');ttq.page();}(window,document,'ttq');\n";
    }
    foreach ($events as $ev) {
        $js .= pixel_event_js(...$ev);
    }
    return $out . "<script>\n{$js}</script>\n";
}

/** Un evento hacia todas las plataformas activas. */
function pixel_event_js(string $name, array $data = [], ?string $eventId = null): string
{
    $ga = ['ViewContent' => 'view_item', 'InitiateCheckout' => 'begin_checkout', 'Purchase' => 'purchase'][$name] ?? null;
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    $fbData = array_intersect_key($data, array_flip(['value', 'currency', 'content_ids', 'content_type', 'content_name', 'num_items']));
    $s = "window.fbq&&fbq('track'," . $j($name) . ',' . $j((object) $fbData) . ($eventId ? ',{eventID:' . $j($eventId) . '}' : '') . ");\n";
    $s .= "window.ttq&&ttq.track(" . $j($name === 'Purchase' ? 'CompletePayment' : $name) . ',' . $j((object) $fbData) . ");\n";
    if ($ga) {
        $gaData = ['currency' => $data['currency'] ?? Country::currency(), 'value' => $data['value'] ?? 0,
                   'items' => $data['items'] ?? []] + ($eventId ? ['transaction_id' => $eventId] : []);
        $s .= "window.gtag?gtag('event'," . $j($ga) . ',' . $j($gaData) . "):dataLayer.push({event:" . $j($ga) . ',ecommerce:' . $j($gaData) . "});\n";
    }
    return $s;
}

function pixel_body(): string
{
    $gtm = preg_replace('/[^A-Z0-9-]/i', '', (string) setting('gtm_id', ''));
    return $gtm ? "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$gtm}\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n" : '';
}
