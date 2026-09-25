'use client';
import {useState} from 'react';

const events=[
 {title:'🏃 Maliebaanloop',date:'Zondag 4 oktober · 10:00',where:'Maliebaan',cost:'€5 per kind',people:'12 kinderen · 6 gezinnen',kind:'coral',text:'Een gezellige ochtend samen hardlopen. Iedereen regelt zelf de inschrijving.'},
 {title:'🦖 Naar Naturalis',date:'Zaterdag 17 oktober · 11:00',where:'Leiden',cost:'€18 kind · €22 volw.',people:'Iedereen koopt eigen ticket',kind:'blue',text:'Museumuitje met ruimte om samen te reizen. Vragen en vervoer regelen we hier.'},
 {title:'🧡 Oud-leerlingen helpen op het plein',date:'Woensdag 28 oktober · 15:00',where:'Schoolplein',cost:'Vrijwilligers welkom',people:'Voor de hele community',kind:'orange',text:'Oud-leerlingen die nog kind zijn kunnen eenvoudig meedoen en helpen tijdens een sport- en spelmiddag.'}
];

export default function Home(){
 const [joined,setJoined]=useState<number[]>([]);
 return <main className="shell">
  <header><div><div className="logo">school<span>plein</span></div><div className="school">Dalton Pieterskerkhof</div></div><div className="avatar">C</div></header>
  <section className="hero"><h1>Wat gebeurt er op het plein?</h1><p>Ontdek wat gezinnen, kinderen en oud-leerlingen samen doen — van school tot buurt.</p></section>
  <nav className="tabs"><b className="active">🏠 Het Plein</b><b>📅 Agenda</b><b>🧡 Community</b><b>🤝 Meehelpen</b></nav>
  <section className="principle"><strong>Iedereen hoort bij het plein.</strong><span>Activiteiten zijn standaard open voor de hele Schoolplein-community. Er zijn geen activiteiten per schoolgroep.</span></section>
  <section className="grid"><div>{events.map((e,i)=><article className="card" key={e.title}><div className={'stripe '+e.kind}/><h2>{e.title}</h2><div className="meta">{e.date}<br/>{e.where}</div><div className="badges"><span>{e.cost}</span><span>{e.people}</span></div><p>{e.text}</p><div className="actions"><button className={joined.includes(i)?'joined':'join'} onClick={()=>setJoined(x=>x.includes(i)?x.filter(n=>n!==i):[...x,i])}>{joined.includes(i)?'✓ Wij doen mee':'＋ Wij doen mee'}</button><button className="secondary">💬 Gesprek</button></div></article>)}</div><aside className="card side"><h3>Op het plein 🧡</h3><p><b>Iedereen</b><br/><small>alle schoolgroepen, gezinnen en kinderen</small></p><p><b>Oud-leerlingen</b><br/><small>kinderen die al van school zijn, zolang ze binnen de afgesproken leeftijd vallen</small></p><p><b>Grachtenvaarders & Dombeklimmers</b><br/><small>blijven herkenbare groepsnamen in de schoolcontext, maar bepalen niet wie mee mag doen</small></p><p><b>Geen WhatsApp nodig</b><br/><small>praktische gesprekken blijven bij de activiteit</small></p></aside></section>
  <button className="fab">＋ Activiteit</button>
 </main>
}
