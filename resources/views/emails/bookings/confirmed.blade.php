<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Confirmation de Réservation</title>
</head>
<body style="font-family: sans-serif; color: #333; line-height: 1.6;">

<h2>Merci pour votre confiance, {{ $booking->guest->name }} !</h2>
<p>Votre paiement a été validé et votre réservation est officiellement **confirmée**.</p>

<hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

### 🏨 Détails de votre séjour
* **Référence :** {{ $booking->booking_reference }}
* **Arrivée (Check-in) :** {{ \Carbon\Carbon::parse($booking->check_in)->format('d/m/Y') }}
* **Départ (Check-out) :** {{ \Carbon\Carbon::parse($booking->check_out)->format('d/m/Y') }}

### 💳 Résumé financier
* **Montant Total Payé :** {{ number_format($booking->total_amount, 2, ',', ' ') }} {{ $booking->currency }}

<p style="margin-top: 30px;">L'établissement se tient prêt à vous accueillir. Bon voyage !</p>
<p><em>L'équipe de votre plateforme.</em></p>

</body>
</html>
