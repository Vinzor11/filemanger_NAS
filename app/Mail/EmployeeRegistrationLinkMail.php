<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeRegistrationLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Employee $employee,
        public readonly string $registrationUrl,
        public readonly string $registrationCode,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Complete your employee registration',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.employee-registration-link',
            with: [
                'employeeName' => $this->employee->full_name ?: $this->employee->employee_no,
                'employeeNo' => $this->employee->employee_no,
                'registrationCode' => $this->registrationCode,
                'registrationUrl' => $this->registrationUrl,
            ],
        );
    }
}
