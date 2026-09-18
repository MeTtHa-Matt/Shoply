<button class="gemini-trigger" type="button" aria-label="Parler avec Gemini" title="Parler avec Gemini">
    <span class="gemini-logo" aria-hidden="true">✦</span>
</button>

<div class="siri-ui" data-siri-ui hidden aria-hidden="true">
    <div class="siri-scrim" data-siri-close></div>
    <div class="siri-island" data-siri-island aria-hidden="true">
        <span class="siri-island-glow"></span>
        <span class="siri-island-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
        <span class="siri-island-label">Shoply</span>
    </div>

    <section class="siri-result" data-siri-result role="dialog" aria-modal="true" aria-labelledby="shoply-result-title" aria-live="polite">
        <button class="siri-close" type="button" data-siri-close aria-label="Fermer">×</button>
        <div class="siri-result-orb" aria-hidden="true"><span></span></div>
        <h2 id="shoply-result-title">Shoply</h2>
        <p class="siri-status" data-siri-status>Je t'écoute...</p>
        <p class="siri-transcript" data-siri-transcript></p>
        <p class="siri-answer" data-siri-answer></p>
        <button class="siri-retry" type="button" data-siri-retry hidden>Réessayer</button>
        <button class="siri-expand" type="button" data-siri-expand aria-label="Ouvrir la conversation">⌄</button>
    </section>

    <section class="siri-conversation" data-siri-conversation role="dialog" aria-modal="true" aria-labelledby="shoply-conversation-title" hidden>
        <header class="siri-conversation-header">
            <div><span class="siri-kicker">SHOPLY</span><h2 id="shoply-conversation-title">Shoply</h2></div>
            <button class="siri-close" type="button" data-siri-close aria-label="Fermer">×</button>
        </header>
        <div class="siri-thread" data-siri-thread aria-live="polite"></div>
        <form class="siri-composer" data-siri-composer>
            <input type="text" name="message" placeholder="Demander à Siri" autocomplete="off" aria-label="Demander à Siri">
            <button class="siri-compose-mic" type="button" data-siri-compose-mic aria-label="Parler à Siri">⌕</button>
            <button class="siri-send" type="submit" aria-label="Envoyer">↑</button>
        </form>
    </section>
</div>