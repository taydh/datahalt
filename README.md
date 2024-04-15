# Datahalt
> A general purpose remote data service

## Applicability
A web based service for client applications to access with regular web request and JSON format (REST).

Personal case: a poor-man system using cheap shared hosting, without remote database support; but we want to perform general SQL query instead of making separate web based service -- and do not find satisfied alternative.

## Features

Work in progress. Current committed features:

Yes, there is Authorization mechanism
- File based server configuration for the clients
- Using TOTP (share symmetric key and exchanged to an Authotization bearer token)
- Optionally, without any token to validate for network isolated server to safe a few millisecond

Big picture for the 'query'
- Single 'query request' consist of one or more 'Entries'
- Parameters can reference result from previously run entries
- Each entriy can choose which connector to use

Installation is 'easy'

- Drop-in files in zip format
- Settings in one PHP file
- Settings in additional configuration files

Hopefully can provide easy to follow installation guide as this intended for traditional web hosting (FTP/Upload) as the word 'easy' is different for everyone.

## Connectors

Relational Database
- File based server configuration for database connection
- Send SQL query and get records
- Execute SQL statement and get result

Local File and Directory
- Read file
- Read directory

Datahalt Recursive
- Connect and query to ANOTHER Datahalt endpoint (normally resides in further unreached domain)

## Disadvantages

- Custom approach, not a standard, locally treated, database connector
- Need to follow/change application data logic with this approach

## Advantages

Still hope this approach lead to better data handling and design:
- Less server controllers, client/UI app directly call business logic queries prepared in server (as json template)
- Did I already mentioned cheap?

## QUERY REQUEST FORMAT

Guide to JSON query request format can be read [here](./GUIDE-QUERY-FORMAT.md).
