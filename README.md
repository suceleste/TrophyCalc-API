# 🎮 TrophyCalc API (Backend)

[![Laravel](https://img.shields.io/badge/Laravel-10.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php)](https://www.php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql)](https://www.mysql.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)

> **Micro-service Backend** gérant la logique métier, l'agrégation de données Steam et le calcul de scoring pour la plateforme TrophyCalc.

---

## 🏗️ Architecture & Features

Ce projet expose une **API RESTful** consommée par le client Frontend (Vue.js). Il est conçu pour traiter de grands volumes de données joueurs en arrière-plan.

### 🔑 Authentification & Sécurité
* Implémentation du protocole **OpenID** pour le "Steam Login".
* Sécurisation des endpoints via **Laravel Sanctum** (Token based auth).

### ⚙️ Data Engineering
* **Steam Web API Wrapper :** Service dédié pour interroger les serveurs Valve.
* **Job Queueing (Redis) :** Traitement asynchrone des imports de bibliothèques de jeux (évite les timeouts HTTP lors des updates massifs).
* **Rate Limiting :** Gestion intelligente des quotas d'appels API externes.

### 🧮 Moteur de Scoring
* Algorithme personnalisé calculant la "Rareté Réelle" d'un succès en fonction des statistiques globales.
* Mise en cache des leaderboards pour optimiser les temps de réponse.

---

## 🛠️ Stack Technique

* **Framework :** Laravel 10 (API Resource, Eloquent, Queues)
* **Base de données :** MySQL 8
* **Cache & Queues :** Redis
* **Serveur :** Nginx / Docker

---
