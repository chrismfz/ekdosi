@extends('install.layout')
@section('title', 'Ολοκληρώθηκε')
@section('subtitle', 'Η εγκατάσταση ολοκληρώθηκε')

@section('content')
    <div class="card">
        <div class="alert alert-ok" style="margin-bottom: 20px;">
            <strong>✓ Έτοιμο!</strong> Η βάση δημιουργήθηκε, οι ρυθμίσεις γράφτηκαν και ο διαχειριστής είναι έτοιμος.
        </div>

        <p>Στοιχεία σύνδεσης:</p>
        <ul>
            <li>Εταιρία: <strong>{{ $company }}</strong></li>
            <li>Διαχειριστής: <strong>{{ $adminEmail }}</strong> (super admin)</li>
            <li>Σύνδεση: <a href="{{ $appUrl }}/admin">{{ $appUrl }}/admin</a></li>
        </ul>

        <p style="text-align: center; margin-top: 20px;">
            <a href="{{ $appUrl }}/admin"><button type="button" class="btn-primary">Σύνδεση στο ekdosi →</button></a>
        </p>
    </div>

    <div class="card">
        <h2>Επόμενα βήματα (σε επίπεδο διακομιστή)</h2>
        <p class="section-hint">Αυτά ο οδηγός δεν μπορεί να τα κάνει μόνος του — χρειάζονται πρόσβαση στο σύστημα:</p>
        <ul>
            @foreach ($checklist as $item)
                <li style="margin-bottom: 10px;">
                    <strong>{{ $item['title'] }}</strong><br>
                    <span class="hint">{{ $item['body'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="card">
        <div class="alert alert-warn" style="margin: 0;">
            <strong>Ασφάλεια:</strong> ο οδηγός εγκατάστασης απενεργοποιήθηκε αυτόματα — η διεύθυνση <code>/install</code> ανακατευθύνει πλέον στο <code>/admin</code> και δεν θα ξανατρέξει.
        </div>
    </div>
@endsection
