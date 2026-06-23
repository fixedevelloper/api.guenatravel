@component('mail::message')
    # Bienvenue sur votre espace de voyage !

    Bonjour {{ $user->name }},

    Votre réservation de vol a été enregistrée avec succès. Pour vous permettre de suivre votre dossier, de modifier vos informations ou de télécharger vos billets électroniques à tout moment, un compte client sécurisé vient de vous être configuré.

    Voici vos informations de connexion exclusives :

    @component('mail::panel')
        **Identifiant :** {{ $user->email }}
        **Mot de passe temporaire :** `{{ $password }}`
    @endcomponent

    *Pour des raisons de sécurité, nous vous conseillons vivement de modifier ce mot de passe dès votre première connexion dans l'onglet "Mon Profil".*

    @component('mail::button', ['url' => url('/login')])
        Accéder à mon espace client
    @endcomponent

    Si vous avez des questions concernant votre billet ou votre itinéraire, notre équipe reste à votre entière disposition.

    Bon voyage,<br>
    L'équipe {{ config('app.name') }}
@endcomponent
