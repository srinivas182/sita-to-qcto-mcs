import { describe, expect, it } from 'vitest';
import { routeFor } from '../src/core/notificationRoute';

describe('notification routes', () => {
  it('opens the document to sign', () => {
    expect(routeFor({ type: 'agreement', signer_id: '01JDEMO0000000000000000000' })).toBe('/agreements/01JDEMO0000000000000000000');
  });

  it('opens an offer', () => {
    expect(routeFor({ type: 'offer', offer_id: '01JDEMO0000000000000000000' })).toBe('/offers/01JDEMO0000000000000000000');
    expect(routeFor({ type: 'offer', offer_id: '../x' })).toBeNull();
  });

  it('ignores anything else, including a malformed id', () => {
    expect(routeFor({ type: 'agreement', signer_id: '../settings' })).toBeNull();
    expect(routeFor({ type: 'agreement', signer_id: 42 })).toBeNull();
    expect(routeFor({ type: 'unknown' })).toBeNull();
    expect(routeFor(undefined)).toBeNull();
  });
});
