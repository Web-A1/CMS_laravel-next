"use client";

import { useEffect, useState } from "react";

import {
  CLIENT_ACCOUNT_CLEARED_EVENT,
  CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT,
  isClientAccountProfile,
  loadClientAccountProfile,
  type ClientAccountProfile,
} from "@/lib/clientAccount";

export function canSearchReviewsAsAdmin(profile: ClientAccountProfile | null): boolean {
  return profile !== null
    && (profile.role === "admin_full" || profile.role === "admin_limited")
    && profile.impersonation?.status !== "active"
    && profile.impersonation?.status !== "expired";
}

export function useAdminReviewSearchVisibility(): boolean {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    let generation = 0;
    const initialGeneration = generation;
    const clear = () => {
      generation += 1;
      setVisible(false);
    };
    const update = (event: Event) => {
      generation += 1;
      const profile: unknown = (event as CustomEvent<unknown>).detail;
      setVisible(isClientAccountProfile(profile) && canSearchReviewsAsAdmin(profile));
    };

    window.addEventListener(CLIENT_ACCOUNT_CLEARED_EVENT, clear);
    window.addEventListener(CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT, update);
    void loadClientAccountProfile().then((profile) => {
      if (generation === initialGeneration) setVisible(canSearchReviewsAsAdmin(profile));
    });

    return () => {
      generation += 1;
      window.removeEventListener(CLIENT_ACCOUNT_CLEARED_EVENT, clear);
      window.removeEventListener(CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT, update);
    };
  }, []);

  return visible;
}
