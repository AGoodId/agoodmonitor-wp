# AGoodMonitor – agentinstruktioner

Läs även `CLAUDE.md` för projektets arkitektur och kodkonventioner.

## Installation och aktivering på en webbplats

- Följ [installationsrutinen i AGoodApp](https://agoodapp.se/projects/6f42d98e-cb94-4c65-b820-0c6c609059ae?tab=anteckningar&doc=90d749b7-8577-43c3-9307-38f17d9f46d1) för AGoodMonitor, Turnstile och WP 2FA.
- Återanvänd eller skapa sajtens installationsuppgift i rätt kundprojekt före arbete. Ange ansvarig, omfattning och godkännande, koppla sessionen och sätt status Pågår.
- Använd [checklistemallen](https://agoodapp.se/projects/6f42d98e-cb94-4c65-b820-0c6c609059ae?tab=anteckningar&doc=c36ad8b9-76e9-4410-82ec-6591a2550d56) för ett sajtunikt protokoll. [agoodsite.se-exemplet](https://agoodapp.se/projects/6f42d98e-cb94-4c65-b820-0c6c609059ae?tab=anteckningar&doc=f29c7a79-115a-4a46-a9e4-b901c02868d1) visar verifierade resultat och begränsningar; det är inte ett godkännande eller testbevis för andra sajter.
- Dokumentera backup, serveråtkomst, separata återställningsvägar, installerade versioner och testresultat i sajtens AGoodApp-uppgift/checklista. Behåll en fungerande administratörssession under proven.
- Hemligheter hör hemma i 1Password. Lägg aldrig secret keys, lösenord, token, QR-hemligheter eller reservkoder i Git, AGoodApp, chatt, skärmdumpar eller loggar. Filtrera känsliga fält vid verktygsavläsning.
- Kontrollera befintlig 2FA/CAPTCHA före byte. Verifiera testkontot före administratören och undvik dubbla formulärskydd eller parallella 2FA-handlers vid loginprov.
- Kundinstallation, aktivering och generell release kräver uttryckligt godkännande för respektive åtgärd och mål. Dokumentationsändringar eller godkänd pilot innebär inget releasegodkännande; kringgå inga gransknings- eller mergekrav.

AGoodApp är huvudplats för process och sajtprotokoll. Kodnära instruktioner och tester finns i `docs/turnstile.md` och `tests/README.md`; håll dem samstämmiga med rutinen när ansvar eller beteende ändras.
