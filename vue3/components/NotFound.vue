<template>
    <div class="NotFound">
        <h2>{{ store.state.strings.vueroutenotfoundsitename }}</h2>
        <h3>{{ store.state.strings.vueroutenotfoundsitedescription }}</h3>
        <div class="learningpaths-edit-add">
        <router-link
            :to="{ name: 'home' }"
            tag="button"
            class="btn btn-primary"
        >
            {{ store.state.strings.vueroutenotfoundsitebtnreload }}
        </router-link>
        </div>
    </div>
  </template>

<script setup lang="ts">
  import { useStore } from 'vuex';
  const store = useStore();
</script>