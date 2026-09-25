'use client';
import {useState} from 'react';
const events=[
 {title:'🏃 Maliebaanloop',date:'Zondag 4 oktober · 10:00',where:'Maliebaan',audience:'Groep 2 t/m 8',cost:'€5 per kind',people:'12 kinderen · 6 gezinnen',kind:'coral'},
 {title:'🦖 Naar Naturalis',date:'Zaterdag 17 oktober · 11:00',where:'Leiden',audience:'Grachtenvaarders + Dombeklimmers',cost:'€18 kind · €22 volw.',people:'Iedereen koopt eigen ticket',kind:'blue'},
 {title:'🧡 Alumni helpen op het plein',date:'Woensdag 28 oktober · 15:00',where:'Schoolplein',audience:'Alumni + groep 1 t/m 4',cost:'Vrijwilligers welkom',people:'Community',kind:'orange'}
];
export default function Home(){const [joined,setJoined]=useState<number[]>([]); return <main className="shell">
 <header><div><div className="logo">school<span>plein</span></div><div className="school">Dalton Pieterskerkhof</div></div><div className="avatar">C</div></header>
 <section className="hero"><h1>Wat gebeurt er op het plein?</h1><p>Ontdek wat gezinnen samen doen — van school tot buurt.</p></section>
 <nav className="tabs"><b className="active">🏠 Het Plein</b><b>📅 Agenda</b><b>🧡 Community</b><b>🤝 Meehelpen</b></nav>
 <section className="grid"><div>{events.map((e,i)=><article className="card" key={e.title}><div className={'stripe '+e.kind}/><h2>{e.title}</h2><div className="meta">{e.date}<br/>{e.where} · {e.audience}</div><div className="badges"><span>{e.cost}</span><span>{e.people}</span></div><p>{i===0?'Een gezellige ochtend samen hardlopen. Iedereen regelt zelf de inschrijving.':i===1?'Museumuitje met ruimte om samen te reizen. Vragen en vervoer regelen we hier.':'Oud-leerlingen helpen jongere kinderen tijdens een sport- en spelmiddag.'}</p><div className="actions"><button className={joined.includes(i)?'joined':'join'} onClick={()=>setJoined(x=>x.includes(i)?x.filter(n=>n!==i):[...x,i])}>{joined.includes(i)?'✓ Wij doen mee':'＋ Wij doen mee'}</button><button className="secondary">💬 Gesprek</button></div></article>)}</div><aside className="card side"><h3>Op het plein 🧡</h3><p><b>Grachtenvaarders</b><br/><small>groep 1–2</small></p><p><b>Dombeklimmers</b><br/><small>groep 1–2</small></p><p><b>Alumni</b><br/><small>helpen, organiseren & ontmoeten</small></p><p><b>Geen WhatsApp nodig</b><br/><small>praktische gesprekken blijven bij de activiteit</small></p></aside></section>
 <button className="fab">＋ Activiteit</button>
 </main>}
