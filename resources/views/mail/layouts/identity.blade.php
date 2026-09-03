<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title')</title>
    <style type="text/css">
        body,
        table,
        td,
        a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        table,
        td {
            mso-table-lspace: 0;
            mso-table-rspace: 0;
        }

        table {
            border-collapse: collapse !important;
        }

        a[x-apple-data-detectors] {
            color: inherit !important;
            font-family: inherit !important;
            font-size: inherit !important;
            font-weight: inherit !important;
            line-height: inherit !important;
            text-decoration: none !important;
        }

        .button-link,
        .button-link:link,
        .button-link:visited {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        @media only screen and (max-width: 620px) {
            .shell {
                padding: 20px 12px !important;
            }

            .card {
                padding: 30px 22px !important;
            }

            .button-table {
                width: 100% !important;
            }

            .button-link {
                display: block !important;
                text-align: center !important;
            }
        }
    </style>
</head>
<body bgcolor="#f7f9f8" style="margin:0;padding:0;background:#f7f9f8;color:#14283a;font-family:Inter,Arial,Helvetica,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">{{ $preheader }}</div>
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f7f9f8" style="width:100%;background:#f7f9f8;">
    <tr>
        <td class="shell" align="center" style="padding:40px 18px;">
            <!--[if mso]>
            <table role="presentation" width="600" border="0" cellspacing="0" cellpadding="0" align="center">
                <tr>
                    <td>
            <![endif]-->
            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;">
                <tr>
                    <td style="padding:0 4px 20px;">
                        <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                            <tr>
                                <td width="46" height="46" align="center" valign="middle" bgcolor="#e9f8f0" role="img" aria-label="{{ __('identity.brand.hexagon_label') }}" style="width:46px;height:46px;border:1px solid #9fd6c1;border-radius:12px;color:#00875f;font-family:Arial,Helvetica,sans-serif;font-size:30px;line-height:46px;">
                                    &#11042;
                                </td>
                                <td valign="middle" style="padding-left:12px;font-family:Inter,Arial,Helvetica,sans-serif;">
                                    <div style="color:#14283a;font-size:18px;font-weight:800;letter-spacing:1.1px;line-height:20px;">{{ __('identity.brand.product') }}</div>
                                    <div style="padding-top:4px;color:#00875f;font-size:12px;font-weight:700;line-height:14px;text-align:right;">{{ __('identity.brand.company') }}</div>
                                </td>
                            </tr>
                        </table>
                        <div style="padding-top:12px;color:#77849b;font-size:13px;line-height:19px;">{{ __('identity.brand.tagline') }}</div>
                    </td>
                </tr>
                <tr>
                    <td class="card" bgcolor="#ffffff" style="padding:42px 40px;background:#ffffff;border:1px solid #dce3e8;border-radius:16px;box-shadow:0 16px 40px rgba(20,40,58,.08);">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:20px 16px 0;color:#77849b;font-size:12px;line-height:18px;text-align:center;">
                        {{ __('identity.brand.footer') }}
                    </td>
                </tr>
            </table>
            <!--[if mso]>
                    </td>
                </tr>
            </table>
            <![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
