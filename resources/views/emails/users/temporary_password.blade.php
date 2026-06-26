<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenue sur votre espace de voyage !</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            width: 100% !important;
            height: 100% !important;
            background-color: #f4f6f8;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        table {
            border-collapse: collapse;
            border-spacing: 0;
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }
        td {
            padding: 0;
        }
        img {
            border: 0;
            outline: none;
            text-decoration: none;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f4f6f8;
            padding-top: 40px;
            padding-bottom: 40px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            border-top: 4px solid #15a4e6;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .content {
            padding: 32px 40px;
        }
        h1 {
            color: #1a1a1a;
            font-size: 24px;
            font-weight: 800;
            margin-top: 0;
            margin-bottom: 24px;
            line-height: 1.3;
        }
        p {
            color: #515151;
            font-size: 15px;
            line-height: 1.6;
            margin-top: 0;
            margin-bottom: 20px;
        }
        .panel {
            background-color: #f8fafc;
            border-left: 4px solid #15a4e6;
            border-radius: 8px;
            padding: 20px;
            margin-top: 24px;
            margin-bottom: 24px;
        }
        .panel p {
            margin-bottom: 10px;
            color: #2d3748;
        }
        .panel p:last-child {
            margin-bottom: 0;
        }
        .code-block {
            background-color: #edf2f7;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 14px;
            color: #e53e3e;
        }
        .note {
            font-size: 13px;
            color: #718096;
            font-style: italic;
            line-height: 1.5;
        }
        .button-container {
            margin-top: 32px;
            margin-bottom: 32px;
            text-align: center;
        }
        .button {
            display: inline-block;
            background-color: #15a4e6;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 28px;
            font-size: 15px;
            font-weight: 700;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(21, 164, 230, 0.2);
            transition: background-color 0.2s ease;
        }
        .footer {
            border-top: 1px solid #edf2f7;
            margin-top: 32px;
            padding-top: 24px;
        }
        @media only screen and (max-width: 600px) {
            .content {
                padding: 24px 20px !important;
            }
            .container {
                border-radius: 0 !important;
            }
        }
    </style>
</head>
<body>
<table role="presentation" class="wrapper">
    <tr>
        <td align="center">
            <!--[if (gte mso 9)|(IE)]>
            <table role="presentation" width="600" align="center"><tr><td>
            <![endif]-->
            <table role="presentation" class="container">
                <tr>
                    <td class="content">
                        <h1>Bienvenue sur votre espace de voyage !</h1>

                        <p>Bonjour {{ $user->name }},</p>

                        <p>Votre réservation de vol a été enregistrée avec succès. Pour vous permettre de suivre votre dossier, de modifier vos informations ou de télécharger vos billets électroniques à tout moment, un compte client sécurisé vient de vous être configuré.</p>

                        <p>Voici vos informations de connexion exclusives :</p>

                        <div class="panel">
                            <p><strong>Identifiant :</strong> {{ $user->email }}</p>
                            <p><strong>Mot de passe temporaire :</strong> <span class="code-block">{{ $password }}</span></p>
                        </div>

                        <p class="note">*Pour des raisons de sécurité, nous vous conseillons vivement de modifier ce mot de passe dès votre première connexion dans l'onglet "Mon Profil".*</p>

                        <div class="button-container">
                            <a href="{{ env('FRONTEND_URL') }}" class="button" target="_blank">Accéder à mon espace client</a>
                        </div>

                        <p>Si vous avez des questions concernant votre billet ou votre itinéraire, notre équipe reste à votre entière disposition.</p>

                        <div class="footer">
                            <p>Bon voyage,<br><strong>L'équipe {{ config('app.name') }}</strong></p>
                        </div>
                    </td>
                </tr>
            </table>
            <!--[if (gte mso 9)|(IE)]>
            </td></tr></table>
            <![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
