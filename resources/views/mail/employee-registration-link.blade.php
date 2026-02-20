<x-mail::message>
# Complete your employee registration

Hello {{ $employeeName }},

An administrator created your employee record. Use the button below to complete your account registration.

<x-mail::button :url="$registrationUrl">
Complete registration
</x-mail::button>

Employee number: **{{ $employeeNo }}**

Registration code: **{{ $registrationCode }}**

If the button does not work, copy and paste this link into your browser:

{{ $registrationUrl }}

This registration code can be used once.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
