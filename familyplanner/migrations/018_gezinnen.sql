-- Households become "gezinnen": one family at one address. A gezin in the address book can be linked to
-- the friend family that uses the planner itself (then their family photo is used when there is none here).
ALTER TABLE fp_households ADD COLUMN linked_family INT UNSIGNED NULL AFTER photo;
