import './globals.css';
import type { Metadata } from 'next';
export const metadata: Metadata = { title: 'Schoolplein', description: 'Het digitale dorpsplein rond school' };
export default function RootLayout({children}:{children:React.ReactNode}) { return <html lang="nl"><body>{children}</body></html>; }
