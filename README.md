# SSM Jelas — Vercel version

This package is the Vercel-ready version of the standalone SSM Jelas v3 checker.

## Files
- `index.html` — current v3 frontend with automatic SSM number checking.
- `api/ssm-status.js` — Vercel Node.js serverless API that performs the SSM e-Search lookup.
- `package.json` — installs the HTML parser used by the serverless function.
- `vercel.json` — gives the lookup function enough execution time for the SSM request/canary flow.

## Deploy
1. Put these files in the root of your GitHub repository.
2. Push/commit to GitHub.
3. Import or redeploy the repository in Vercel.
4. Vercel will run `npm install` automatically and expose the checker endpoint at `/api/ssm-status`.

No PHP is required in this version.

## Accepted SSM number formats
The same formats used by the existing One Stop validation flow are retained: 12 digits, or old 9-character formats (`123456789`, `A12345678`, `AB1234567`) with an optional suffix such as `-H`.

## Important
The lookup depends on the external SSM e-Search page continuing to accept server-side requests and retaining its current HTML/form structure. If SSM changes or blocks requests from Vercel infrastructure, the checker will return `unavailable` and the API will need to be adjusted.
