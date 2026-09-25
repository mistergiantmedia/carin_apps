always push to git when ready

the user making jouwschoolplein on my claude account does not know a single thing about programming.
all prompts in Dutch are the user with no programming knowledge 
all prompts in English are from a user with programming knowledge


We don't have local PHP so push to test, we are in demo/concept mode not live
## Database: shared with jouwschoolplein
The family planner uses the **same MySQL database as jouwschoolplein**. Every table of this app
starts with `fp_` (including `fp_schema_migrations`), and every constraint name starts with `fp_`.
Never create or touch a table without the `fp_` prefix: those belong to jouwschoolplein.
