@extends('mail.layouts.identity')

@section('title', __('identity.password_reset.title'))

@section('content')
    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;">
        <tr>
            <td style="padding:0 0 10px;color:#00875f;font-family:Inter,Arial,Helvetica,sans-serif;font-size:12px;font-weight:800;letter-spacing:1.3px;line-height:18px;text-transform:uppercase;">
                {{ __('identity.password_reset.eyebrow') }}
            </td>
        </tr>
        <tr>
            <td style="padding:0 0 18px;color:#14283a;font-family:Inter,Arial,Helvetica,sans-serif;font-size:28px;font-weight:800;letter-spacing:-.5px;line-height:35px;">
                {{ __('identity.password_reset.title') }}
            </td>
        </tr>
        <tr>
            <td style="padding:0 0 14px;color:#14283a;font-family:Inter,Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;">
                {{ __('identity.common.greeting', ['name' => $organizationUserName]) }}
            </td>
        </tr>
        <tr>
            <td style="padding:0 0 26px;color:#77849b;font-family:Inter,Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;">
                {{ __('identity.password_reset.introduction') }}
            </td>
        </tr>
        <tr>
            <td style="padding:0 0 28px;">
                <table class="button-table" role="presentation" border="0" cellspacing="0" cellpadding="0" style="width:auto;">
                    <tr>
                        <td align="center" bgcolor="#005a43" style="background:#005a43;border:1px solid #005a43;border-radius:9px;">
                            <a class="button-link" href="{{ $actionUrl }}" target="_blank" style="display:inline-block;padding:14px 23px;color:#ffffff !important;-webkit-text-fill-color:#ffffff;font-family:Inter,Arial,Helvetica,sans-serif;font-size:15px;font-weight:800;line-height:20px;text-decoration:none;">
                                <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff;">{{ __('identity.password_reset.action') }}</span>
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding:0 0 22px;color:#77849b;font-family:Inter,Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;">
                {{ __('identity.common.copy_link') }}<br>
                <a href="{{ $actionUrl }}" target="_blank" style="color:#005a43;text-decoration:underline;word-break:break-all;">{{ $actionUrl }}</a>
            </td>
        </tr>
        <tr>
            <td>
                <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#e9f8f0" style="width:100%;background:#e9f8f0;border:1px solid #b8dfd0;border-radius:10px;">
                    <tr>
                        <td style="padding:15px 17px;color:#405365;font-family:Inter,Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;">
                            {{ __('identity.password_reset.notice') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endsection
