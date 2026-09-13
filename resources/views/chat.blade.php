<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>TPE Assistant</title></head>
<body>
<h1>TPE Assistant</h1>
<p>Connecté : {{ auth()->user()->email }}</p>
<form method="post" action="/logout"><button type="submit">Se déconnecter</button></form>
<form id="chat-form">
    <textarea id="message" required placeholder="Posez une question..."></textarea>
    <button type="submit">Envoyer</button>
</form>
<pre id="answer"></pre>
<script>
const form = document.getElementById('chat-form');
form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const response = await fetch('/chat', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json'},
        body: JSON.stringify({message: document.getElementById('message').value})
    });
    const data = await response.json();
    document.getElementById('answer').textContent = data.answer ?? data.message ?? 'Erreur';
});
</script>
</body>
</html>