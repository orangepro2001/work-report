/*
 * Real-time document editing (docs/documents.md).
 *
 * Yjs is the merge engine and y-websocket carries the updates; this file only
 * exposes them to the page. It is the one bundle in the project that needs a build
 * step — the libraries ship as a module graph, and hand-vendoring that graph is
 * exactly the fragility AGENTS.md warns about.
 *
 *   npm install && npm run build   (or the `node` stage in deploy/Containerfile)
 */
import * as Y from 'yjs';
import { WebsocketProvider } from 'y-websocket';
import { CodeMirrorBinding } from 'y-codemirror';

window.WRCollab = { Y, WebsocketProvider, CodeMirrorBinding };
