create extension if not exists "pgcrypto";

create type role_enum as enum ('PARENT','STUDENT','ALUMNI','VOLUNTEER','ORGANIZER','SCHOOL_ADMIN');
create table if not exists schools (id uuid primary key default gen_random_uuid(), name text not null, created_at timestamptz default now());
create table if not exists profiles (id uuid primary key references auth.users(id) on delete cascade, display_name text, created_at timestamptz default now());
create table if not exists school_memberships (id uuid primary key default gen_random_uuid(), school_id uuid references schools(id) on delete cascade, user_id uuid references profiles(id) on delete cascade, role role_enum not null default 'PARENT', unique(school_id,user_id));
create table if not exists children (id uuid primary key default gen_random_uuid(), school_id uuid references schools(id) on delete cascade, parent_user_id uuid references profiles(id) on delete cascade, first_name text not null, group_name text, created_at timestamptz default now());
create table if not exists activities (id uuid primary key default gen_random_uuid(), school_id uuid references schools(id) on delete cascade, created_by uuid references profiles(id), title text not null, description text, location text, start_at timestamptz not null, end_at timestamptz, audience text[] default '{}', max_participants int, price_type text, price_amount numeric, price_description text, payment_method text, status text not null default 'PLANNED', created_at timestamptz default now());
create table if not exists activity_participants (activity_id uuid references activities(id) on delete cascade, user_id uuid references profiles(id) on delete cascade, child_ids uuid[] default '{}', status text not null default 'JOINED', created_at timestamptz default now(), primary key(activity_id,user_id));
create table if not exists activity_messages (id uuid primary key default gen_random_uuid(), activity_id uuid references activities(id) on delete cascade, user_id uuid references profiles(id) on delete cascade, body text not null, created_at timestamptz default now());
create table if not exists reports (id uuid primary key default gen_random_uuid(), reporter_id uuid references profiles(id), activity_id uuid references activities(id), message_id uuid references activity_messages(id), reason text not null, created_at timestamptz default now());
create table if not exists community_settings (school_id uuid primary key references schools(id) on delete cascade, monthly_activity_limit int default 15);

alter table schools enable row level security; alter table profiles enable row level security; alter table school_memberships enable row level security; alter table children enable row level security; alter table activities enable row level security; alter table activity_participants enable row level security; alter table activity_messages enable row level security; alter table reports enable row level security;

-- Security definer helper: avoids infinite RLS recursion when school_memberships policies query school_memberships
create or replace function public.is_school_member(sid uuid) returns boolean
language sql stable security definer set search_path = public as $$
  select exists(select 1 from school_memberships where school_id = sid and user_id = auth.uid())
$$;

create policy "members can view their schools" on schools for select using (exists(select 1 from school_memberships m where m.school_id=schools.id and m.user_id=auth.uid()));
create policy "users can view own profile" on profiles for select using (id=auth.uid());
create policy "members can view memberships" on school_memberships for select using (user_id=auth.uid() or public.is_school_member(school_id));
create policy "members can view activities" on activities for select using (exists(select 1 from school_memberships m where m.school_id=activities.school_id and m.user_id=auth.uid()));
create policy "members can create activities" on activities for insert with check (created_by=auth.uid() and exists(select 1 from school_memberships m where m.school_id=activities.school_id and m.user_id=auth.uid()));
create policy "members can view participants" on activity_participants for select using (exists(select 1 from activities a join school_memberships m on m.school_id=a.school_id where a.id=activity_participants.activity_id and m.user_id=auth.uid()));
create policy "members can join" on activity_participants for insert with check (user_id=auth.uid());
create policy "members can view messages" on activity_messages for select using (exists(select 1 from activities a join school_memberships m on m.school_id=a.school_id where a.id=activity_messages.activity_id and m.user_id=auth.uid()));
create policy "members can post messages" on activity_messages for insert with check (user_id=auth.uid() and exists(select 1 from activities a join school_memberships m on m.school_id=a.school_id where a.id=activity_messages.activity_id and m.user_id=auth.uid()));

insert into schools(name) select 'Dalton Pieterskerkhof' where not exists (select 1 from schools where name='Dalton Pieterskerkhof');
