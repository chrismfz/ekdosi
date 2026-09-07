<!DOCTYPE html>
<html lang="el">
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #222; line-height: 1.5;">
    <p>Το αίτημά σας <strong>{{ $ticket->reference }}</strong> — «{{ $ticket->subject }}» — έκλεισε.</p>
    <p>Θα εκτιμούσαμε πολύ μια σύντομη αξιολόγηση της εξυπηρέτησης:</p>
    <p style="margin: 24px 0;">
        <a href="{{ $url }}" style="background: #2563eb; color: #fff; text-decoration: none; padding: 12px 20px; border-radius: 8px; font-weight: 600; display: inline-block;">Αξιολόγηση εξυπηρέτησης</a>
    </p>
    <p style="color: #888; font-size: 12px;">Αν το κουμπί δεν λειτουργεί, αντιγράψτε τον σύνδεσμο: <br>{{ $url }}</p>
</body>
</html>
