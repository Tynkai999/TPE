<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Connexion</title></head>
<body>
<h1>Connexion TPE Assistant</h1>
<form method="post" action="/login">
    @csrf
    <label>Email <input type="email" name="email" required></label>
    <label>Mot de passe <input type="password" name="password" required></label>
    <label><input type="checkbox" name="remember"> Se souvenir de moi</label>
    <button type="submit">Se connecter</button>
</form>
@if ($errors->any()) <p>{{ $errors->first() }}</p> @endif
<a href="/register">Créer un compte</a>
</body>
</html>