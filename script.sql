create table clients
(
    id           int auto_increment
        primary key,
    kundennummer varchar(20)  not null,
    name         varchar(100) not null,
    email        varchar(100) null,
    adresse      text         null,
    plz          varchar(10)  null,
    ort          varchar(100) null,
    telefon      varchar(50)  null,
    firma        varchar(255) null,
    atu          varchar(50)  null,
    firmenname   varchar(128) null,
    constraint kundennummer
        unique (kundennummer),
    constraint uniq_kundennummer
        unique (kundennummer)
);

create table angebote
(
    id             int auto_increment
        primary key,
    client_id      int                                                       not null,
    angebotsnummer varchar(20)                                               not null,
    datum          date                                                      null,
    betrag         decimal(10, 2)                            default 0.00    not null,
    gueltig_bis    date                                                      null,
    status         enum ('offen', 'angenommen', 'abgelehnt') default 'offen' null,
    pdf_path       varchar(255)                                              null,
    hinweis        text                                                      null,
    constraint angebote_ibfk_1
        foreign key (client_id) references clients (id)
);

create index client_id
    on angebote (client_id);

create table lieferschein_positionen
(
    id              int auto_increment
        primary key,
    lieferschein_id int    null,
    produkt_id      int    null,
    menge           double null
)
    charset = utf8mb4;

create table lieferscheine
(
    id               int auto_increment
        primary key,
    nummer           varchar(64)                           null,
    client_id        int                                   null,
    datum            date                                  null,
    bemerkung        text                                  null,
    kundennummer     varchar(64)                           null,
    status           varchar(20) default 'open'            not null,
    lieferdatum      date                                  null,
    erstellungsdatum timestamp   default CURRENT_TIMESTAMP null,
    lieferadresse_id int                                   null,
    bestellnummer    varchar(128)                          null
)
    charset = utf8mb4;

create index idx_lieferscheine_status
    on lieferscheine (status);

create table products
(
    id          bigint unsigned auto_increment
        primary key,
    sku         varchar(100)                             null,
    name        varchar(255)                             not null,
    description text                                     null,
    unit        varchar(50)    default 'stk'             not null,
    price_net   decimal(12, 2) default 0.00              not null,
    tax_rate    decimal(5, 2)  default 0.00              not null,
    created_at  timestamp      default CURRENT_TIMESTAMP not null,
    updated_at  timestamp                                null on update CURRENT_TIMESTAMP,
    constraint sku
        unique (sku)
);

create index idx_products_name
    on products (name);

create table produkt_kategorien
(
    id         int auto_increment
        primary key,
    name       varchar(100)         not null,
    sort_order int        default 0 null,
    is_active  tinyint(1) default 1 null
)
    charset = utf8mb4;

create table produkte
(
    id            int auto_increment
        primary key,
    artikelnummer varchar(50)                              null,
    name          varchar(255)                             not null,
    beschreibung  text                                     null,
    preis         decimal(10, 2) default 0.00              not null,
    mwst          decimal(5, 2)  default 20.00             not null,
    created_at    timestamp      default CURRENT_TIMESTAMP null,
    category_id   int                                      null,
    constraint artikelnummer
        unique (artikelnummer),
    constraint fk_produkte_kategorie
        foreign key (category_id) references produkt_kategorien (id)
            on delete set null
);

create table angebot_positionen
(
    id           int auto_increment
        primary key,
    angebot_id   int                         not null,
    produkt_id   int                         null,
    menge        decimal(10, 2) default 1.00 not null,
    einzelpreis  decimal(10, 2) default 0.00 not null,
    gesamt       decimal(10, 2) default 0.00 not null,
    beschreibung text                        null,
    constraint angebot_positionen_ibfk_1
        foreign key (angebot_id) references angebote (id),
    constraint angebot_positionen_ibfk_2
        foreign key (produkt_id) references produkte (id)
)
    charset = utf8mb4;

create index angebot_id
    on angebot_positionen (angebot_id);

create index produkt_id
    on angebot_positionen (produkt_id);

create table produkt_preise
(
    id         int auto_increment
        primary key,
    produkt_id int            not null,
    menge      int            not null,
    preis      decimal(10, 2) not null,
    constraint produkt_preise_ibfk_1
        foreign key (produkt_id) references produkte (id)
            on delete cascade
);

create index produkt_id
    on produkt_preise (produkt_id);

create table rechnungen
(
    id               int auto_increment
        primary key,
    client_id        int                                                  not null,
    rechnungsnummer  varchar(20)                                          not null,
    datum            date                                                 null,
    betrag           decimal(10, 2)                       default 0.00    not null,
    faelligkeit      date                                                 null,
    status           enum ('offen', 'bezahlt', 'mahnung') default 'offen' null,
    total            decimal(10, 2)                       default 0.00    null,
    mahn_stufe       int                                  default 0       null,
    pdf_path         varchar(255)                                         null,
    gesamt           decimal(10, 2)                       default 0.00    null,
    beschreibung     text                                                 null,
    hinweis          text                                                 null,
    paid_at          datetime                                             null,
    verwendungszweck varchar(64)                                          not null,
    constraint verwendungszweck
        unique (verwendungszweck),
    constraint rechnungen_ibfk_1
        foreign key (client_id) references clients (id)
);

create table mahnungen
(
    id               int auto_increment
        primary key,
    rechnung_id      int                                  not null,
    stufe            int        default 1                 null,
    created_at       datetime   default CURRENT_TIMESTAMP not null,
    created_by       varchar(100)                         null,
    datum            date                                 null,
    text             text                                 null,
    sent_email       tinyint(1) default 0                 null,
    email_result     varchar(255)                         null,
    note             text                                 null,
    pdf_path         varchar(255)                         null,
    net_amount       decimal(12, 2)                       null,
    ust_percent      decimal(5, 2)                        null,
    ust_amount       decimal(12, 2)                       null,
    interest_percent decimal(5, 2)                        null,
    interest_amount  decimal(12, 2)                       null,
    stage_fee        decimal(12, 2)                       null,
    total_due        decimal(12, 2)                       null,
    days_overdue     int                                  null,
    constraint fk_mahnungen_rechnung
        foreign key (rechnung_id) references rechnungen (id)
            on update cascade on delete cascade,
    constraint mahnungen_ibfk_1
        foreign key (rechnung_id) references rechnungen (id)
);

create index idx_created_at
    on mahnungen (created_at);

create index idx_mahnungen_rechnung_id
    on mahnungen (rechnung_id);

create index idx_rechnung
    on mahnungen (rechnung_id);

create index idx_stufe
    on mahnungen (stufe);

create index client_id
    on rechnungen (client_id);

create index idx_rechnungen_datum
    on rechnungen (datum);

create index idx_rechnungen_faelligkeit
    on rechnungen (faelligkeit);

create table rechnungs_positionen
(
    id           int auto_increment
        primary key,
    rechnung_id  int            not null,
    produkt_id   int            null,
    menge        int            not null,
    einzelpreis  decimal(10, 2) not null,
    gesamt       decimal(10, 2) not null,
    beschreibung text           null,
    constraint rechnungs_positionen_ibfk_1
        foreign key (rechnung_id) references rechnungen (id)
            on delete cascade,
    constraint rechnungs_positionen_ibfk_2
        foreign key (produkt_id) references produkte (id)
            on delete cascade
);

create index produkt_id
    on rechnungs_positionen (produkt_id);

create index rechnung_id
    on rechnungs_positionen (rechnung_id);

create table settings_company
(
    id            int auto_increment
        primary key,
    company_name  varchar(255)                        null,
    creditor_name varchar(120)                        null,
    address_line1 varchar(255)                        null,
    address_line2 varchar(255)                        null,
    phone         varchar(100)                        null,
    email         varchar(255)                        null,
    website       varchar(255)                        null,
    vat           varchar(100)                        null,
    iban          varchar(100)                        null,
    bic           varchar(100)                        null,
    logo_path     varchar(255)                        null,
    updated_at    timestamp default CURRENT_TIMESTAMP null on update CURRENT_TIMESTAMP
);

create table users
(
    id            int unsigned auto_increment
        primary key,
    username      varchar(50)                                        null,
    email         varchar(255)                                       not null,
    password_hash varchar(255)                                       not null,
    name          varchar(255)                                       null,
    company       varchar(255)                                       null,
    role          enum ('seller', 'admin') default 'seller'          not null,
    is_active     tinyint(1)               default 1                 not null,
    created_at    datetime                 default CURRENT_TIMESTAMP not null,
    constraint email
        unique (email)
);

create table remember_tokens
(
    id             bigint unsigned auto_increment
        primary key,
    user_id        int unsigned                       not null,
    selector       char(32)                           not null,
    validator_hash char(64)                           not null,
    expires_at     datetime                           not null,
    created_at     datetime default CURRENT_TIMESTAMP not null,
    constraint selector
        unique (selector),
    constraint remember_tokens_ibfk_1
        foreign key (user_id) references users (id)
            on delete cascade
);

create index user_id
    on remember_tokens (user_id);

