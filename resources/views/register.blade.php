<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Inscription</title></head>
<body>
<h1>Créer un compte TPE Assistant</h1>
<form method="post" action="/register">
    @csrf
    <label>Nom <input name="name" required></label>
    <label>Email <input type="email" name="email" required></label>
    <label>Mot de passe <input type="password" name="password" required></label>
    <label>Confirmation <input type="password" name="password_confirmation" required></label>
    <button type="submit">Créer le compte</button>
</form>
@if ($errors->any()) <p>{{ $errors->first() }}</p> @endif
<a href="/login">Se connecter</a>
</body>
</html>