# Schoolplein

Een digitaal dorpsplein rond school. Eerste echte codebasis voor Dalton Pieterskerkhof.

## Stack
Next.js + TypeScript + Supabase.

## Lokaal starten
1. Installeer Node.js 20+.
2. `npm install`
3. Kopieer `.env.example` naar `.env.local` en vul Supabase URL + anon key in.
4. `npm run dev`
5. Open http://localhost:3000

## Supabase
Maak een Supabase-project en voer `supabase/schema.sql` uit in de SQL editor. Daarna kunnen we auth, echte activiteiten en activiteitengesprekken aansluiten.

## Productregels die in de volgende stap leidend zijn
- Geen WhatsApp-groepen voor activiteiten.
- Geen likes, volgers of populariteitsscores.
- Geen privé-DM's in de MVP.
- Communicatie hoort bij de activiteit.
- Kinderen worden door ouder/verzorger beheerd.
- Deelnemersinformatie wordt bewust en terughoudend zichtbaar gemaakt.
- Community/alumni/extra categorieën zijn oranje.
- Maandelijkse activiteitlimiet wordt configureerbaar gemaakt en eerst getest in de pilot.
